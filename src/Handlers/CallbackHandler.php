<?php

declare(strict_types=1);

namespace P1Bot\Handlers;

use P1Bot\App\Announcer;
use P1Bot\App\DraftCards;
use P1Bot\App\PrivateGroups;
use P1Bot\App\PrivateViews;
use P1Bot\Domain\Exception\DomainError;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\TeamService;
use P1Bot\Domain\TournamentService;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Repository\ConversationRepository;
use P1Bot\Support\Clock;
use P1Bot\Telegram\BotIdentity;
use P1Bot\Telegram\CallbackQuery;
use P1Bot\Telegram\TelegramApi;
use P1Bot\View\DeepLink;
use P1Bot\View\Html;
use P1Bot\View\Messages;

/**
	Inline buttons. In private: psolo:<t>, punsolo:<t>, pteam:<t>, pleave:<team>, pdissolve:<team>,
	popen:<t>, grp:<chat id>, and pub:<t> («Публикуем») / fix:<t> («Поправить») under the draft card.
	Buttons of old group messages (solo/unsolo/howto/pub/fix) only point to the private chat.
*/
class CallbackHandler {
	protected const LEGACY = ['solo', 'unsolo', 'howto', 'pub', 'fix'];

	public function __construct(
		protected TelegramApi $api,
		protected BotIdentity $bot,
		protected TournamentService $tournaments,
		protected TeamService $teams,
		protected Announcer $announcer,
		protected DraftCards $drafts,
		protected ?PrivateGroups $groups = null,
		protected ?PrivateViews $views = null,
		protected ?ConversationRepository $conversations = null,
		protected ?Clock $clock = null,
		protected int $conversation_ttl_minutes = 30,
	) {
	}

	/**
		For the update log: how the button is treated.
	*/
	public function describe(CallbackQuery $query): string {
		$action = (string) strtok($query->data, ':');
		if ($query->chat_id !== null && $query->chat_id < 0) {
			return in_array($action, self::LEGACY, true) ? 'group: old button ' . $query->data . ' -> private' : 'group: unknown button';
		}
		return 'private: button ' . $query->data;
	}

	public function handle(CallbackQuery $query, Player $player): void {
		if (!preg_match('/^([a-z]+):(-?\d+)$/', $query->data, $m)) {
			$this->api->answerCallbackQuery($query->id);
			return;
		}
		$action = $m[1];
		$id = (int) $m[2];
		if ($query->chat_id === null || $query->chat_id < 0) {
			$this->legacy($query, $action, $id);
			return;
		}
		try {
			$this->privateButton($query, $player, $action, $id);
		} catch (DomainError $e) {
			$this->api->answerCallbackQuery($query->id, $e->getMessage(), true);
		}
	}

	/**
		Old group buttons: a hint plus a deep link into the private chat.
	*/
	protected function legacy(CallbackQuery $query, string $action, int $id): void {
		if (!in_array($action, self::LEGACY, true)) {
			$this->api->answerCallbackQuery($query->id);
			return;
		}
		$this->api->answerCallbackQuery(
			$query->id,
			Messages::LEGACY_BUTTON,
			false,
			DeepLink::tournament($this->bot->username, $id),
		);
	}

	protected function privateButton(CallbackQuery $query, Player $player, string $action, int $id): void {
		$chat_id = (int) $query->chat_id;
		switch ($action) {
			case 'grp':
				if ($this->groups === null || !$this->groups->choose($player->id, $id)) {
					$this->api->answerCallbackQuery($query->id, 'Этой группы больше нет.', true);
					return;
				}
				$title = $this->groups->title($id);
				$this->api->answerCallbackQuery($query->id, 'Группа «' . $title . '»');
				$this->api->sendMessage(
					$chat_id,
					'Ок, работаем с группой ' . Html::b($title) . '. Повтори, пожалуйста, что нужно сделать 🙂',
				);
				return;
			case 'pub':
				$t = $this->tournaments->get($id);
				$this->drafts->publish($player->id, $t->id, $query->message_id);
				$this->api->answerCallbackQuery($query->id, 'Опубликовано!');
				$this->api->sendMessage($chat_id, $this->publishedText($t));
				return;
			case 'fix':
				$t = $this->tournaments->get($id);
				if (!$this->drafts->canConfirm($t, $player->id)) {
					$this->api->answerCallbackQuery(
						$query->id,
						'Править черновик может только его организатор или тот, кто попросил показать карточку.',
						true,
					);
					return;
				}
				$this->api->answerCallbackQuery(
					$query->id,
					'Напиши, что поправить: например «время 21:00» или «уровень 10».',
					true,
				);
				return;
			case 'popen':
				$this->open($query, $player, $this->tournaments->get($id));
				return;
			case 'psolo':
				$t = $this->tournaments->get($id);
				$this->assertMember($t, $player);
				$this->teams->joinSolo($t->id, $player->id);
				$this->announcer->refresh($t->id);
				$placement = $this->tournaments->lineup($t->id)->placementOf($player->id);
				$text = $placement === 'reserve'
					? 'Записала тебя соло, но мест уже нет: ты в резерве. Если кто-то снимется, поднимешься сам.'
					: 'Записала тебя соло! Команду соберёте уже в игре.';
				$this->done($query, $t, $player, $text);
				return;
			case 'punsolo':
				$t = $this->tournaments->get($id);
				$this->teams->leaveSolo($t->id, $player->id);
				$this->announcer->refresh($t->id);
				$this->done($query, $t, $player, 'Сняла тебя с турнира. Передумаешь — кнопки ниже.');
				return;
			case 'pteam':
				$this->askTeam($query, $player, $this->tournaments->get($id));
				return;
			case 'pleave':
				$entry = $this->teams->get($id);
				$t = $this->tournaments->get($entry->team->tournament_id);
				$this->teams->leaveTeam($player->id, $entry->team->id);
				$this->announcer->refresh($t->id);
				$this->done($query, $t, $player, sprintf('Ты вышел из команды «%s».', $entry->team->name));
				return;
			case 'pdissolve':
				$entry = $this->teams->get($id);
				$t = $this->tournaments->get($entry->team->tournament_id);
				$this->teams->withdrawTeam($player->id, $entry->team->id);
				$this->announcer->refresh($t->id);
				$this->done(
					$query,
					$t,
					$player,
					sprintf('Команда «%s» распущена и снята с турнира.', $entry->team->name),
				);
				return;
		}
		$this->api->answerCallbackQuery($query->id);
	}

	protected function open(CallbackQuery $query, Player $player, Tournament $t): void {
		if ($this->groups !== null) {
			$this->groups->choose($player->id, $t->chat_id);
		}
		$this->api->answerCallbackQuery($query->id);
		if ($t->status === TournamentStatus::DRAFT) {
			$this->tournaments->assertCanManage($player->id, $t->id);
			$this->drafts->show($t->id, (int) $query->chat_id, null, $player->id);
			return;
		}
		if ($this->views !== null) {
			$this->views->tournament((int) $query->chat_id, $t, $player);
		}
	}

	/**
		«Собрать команду»: the name and the players come as the next message, the AI continues
		the dialog (the question is saved as conversation memory).
	*/
	protected function askTeam(CallbackQuery $query, Player $player, Tournament $t): void {
		$this->assertMember($t, $player);
		if ($t->status !== TournamentStatus::REGISTRATION) {
			$this->api->answerCallbackQuery($query->id, 'Регистрация на этот турнир сейчас закрыта.', true);
			return;
		}
		$existing = $this->teams->teamOf($t->id, $player->id);
		if ($existing !== null) {
			$this->api->answerCallbackQuery($query->id, sprintf('Ты уже в команде «%s».', $existing->team->name), true);
			return;
		}
		if ($this->groups !== null) {
			$this->groups->choose($player->id, $t->chat_id);
		}
		$number = (int) $this->announcer->number($t)->number;
		$others = $t->team_size - 1;
		$question = sprintf(
			'Собираем команду на №%d %s. Как назовём команду и кто в ней? В команде %d %s: ты и ещё %d. '
				. "Напиши, например:\n<i>Wolves: @vasya, @petya</i>\n"
				. 'Можно сначала только название — игроков добавишь потом.',
			$number,
			Html::b($t->title),
			$t->team_size,
			$t->team_size === 1 ? 'игрок' : ($t->team_size < 5 ? 'игрока' : 'игроков'),
			$others,
		);
		$this->api->answerCallbackQuery($query->id);
		$message_id = $this->api->sendMessage((int) $query->chat_id, $question);
		if ($this->conversations !== null && $this->clock !== null) {
			$this->conversations->save(
				null,
				(int) $query->chat_id,
				$player->id,
				$message_id,
				[
					[
						'role' => 'assistant',
						'content' => sprintf(
							'Собираем команду на турнир №%d «%s» (капитан — автор). Жду название команды и игроков; '
								. 'когда пришлёт — вызови register_team для турнира №%d.',
							$number,
							$t->title,
							$number,
						),
					],
				],
				$this->clock->now()->modify("+{$this->conversation_ttl_minutes} minutes"),
			);
		}
	}

	/**
		Toast plus a fresh message with the buttons for the new state.
	*/
	protected function done(CallbackQuery $query, Tournament $t, Player $player, string $text): void {
		$this->api->answerCallbackQuery($query->id, $text);
		$buttons = $this->views !== null ? $this->views->buttons($this->tournaments->get($t->id), $player) : [];
		$summary = $this->tournaments->lineup($t->id)->summary();
		$this->api->sendMessage(
			(int) $query->chat_id,
			Html::e($text) . "\n" . Html::b($t->title) . ': ' . Html::e($summary),
			null,
			$buttons,
		);
	}

	protected function assertMember(Tournament $t, Player $player): void {
		if ($this->groups !== null) {
			$this->groups->assertMember($t->chat_id, $player->tg_user_id);
		}
	}

	protected function publishedText(Tournament $t): string {
		$where = $this->groups !== null ? ' в группе ' . Html::b($this->groups->title($t->chat_id)) : ' в группе';
		return 'Опубликовала! Анонс уже' . $where . ', регистрация открыта 🎉';
	}
}
