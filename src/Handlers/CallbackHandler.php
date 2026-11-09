<?php

declare(strict_types=1);

namespace P1Bot\Handlers;

use P1Bot\App\Announcer;
use P1Bot\App\PendingPlayerRunner;
use P1Bot\App\TeamDisbandNotifier;
use P1Bot\App\DraftCards;
use P1Bot\App\PrivateGroups;
use P1Bot\App\PrivateViews;
use P1Bot\App\OrganizerTeams;
use P1Bot\App\PendingTextGuard;
use P1Bot\App\TeamAddFlow;
use P1Bot\App\TeamCards;
use P1Bot\Domain\Exception\DomainError;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\TeamService;
use P1Bot\Domain\TournamentService;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Repository\ConversationRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Clock;
use P1Bot\Support\RuPlural;
use P1Bot\Telegram\BotIdentity;
use P1Bot\Telegram\CallbackQuery;
use P1Bot\Telegram\TelegramApi;
use P1Bot\View\DeepLink;
use P1Bot\View\Html;
use P1Bot\View\Messages;

/**
	Inline buttons. In private: psolo:<t>, punsolo:<t>, pteam:<t>, pleave:<team>, pdissolve:<team>,
	popen:<t>, grp:<chat id>, pub:<t> / fix:<t>, tcancel/tcancely/tcanceln,<t>, oteams/otnew/otren/otcap/otsetc/otdel/otdelo/otcc
	(organizer team manage), padd/prem/tadd/trem/tcard. Old group buttons only point to private.
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
		protected ?TeamDisbandNotifier $disband_notifier = null,
		protected ?PendingPlayerRunner $pending_runner = null,
		protected ?TeamAddFlow $team_add = null,
		protected ?TeamCards $team_cards = null,
		protected ?PlayerRepository $players = null,
		protected ?OrganizerTeams $organizer = null,
		protected ?PendingTextGuard $pending_guard = null,
	) {
	}

	/**
		For the update log: how the button is treated.
	*/
	public function describe(CallbackQuery $query): string {
		if (str_starts_with($query->data, 'sug:')) {
			return 'suggest pick ' . $query->data;
		}
		if (preg_match('/^(padd|prem|tadd|trem|tcard|oteams|otnew|otren|otcap|otsetc|otdel|otdelo|otcc):/', $query->data)) {
			return 'private: button ' . $query->data;
		}
		$action = (string) strtok($query->data, ':');
		if ($query->chat_id !== null && $query->chat_id < 0) {
			return in_array($action, self::LEGACY, true) ? 'group: old button ' . $query->data . ' -> private' : 'group: unknown button';
		}
		return 'private: button ' . $query->data;
	}

	public function handle(CallbackQuery $query, Player $player): void {
		if (
			$query->chat_id !== null
			&& $query->chat_id > 0
			&& $query->data === PendingTextGuard::CANCEL_DATA
			&& $this->pending_guard !== null
		) {
			$this->pending_guard->handleCancel($query, $player);
			return;
		}
		if (preg_match('/^sug:(\d+):(\d+)$/', $query->data, $sm) && $this->pending_runner !== null) {
			$this->pending_runner->handle(
				(int) $sm[1],
				(int) $sm[2],
				$player,
				(int) $query->chat_id,
				$query->id,
				$query->message_id,
			);
			return;
		}
		if ($query->chat_id !== null && $query->chat_id > 0 && $this->pending_guard !== null) {
			$this->pending_guard->abortIfForeignCallback($player->id, (int) $query->chat_id, $query->data);
		}
		if ($query->chat_id !== null && $query->chat_id > 0 && $this->handleTeamAddCallbacks($query, $player)) {
			return;
		}
		if ($query->chat_id !== null && $query->chat_id > 0 && $this->handleOrganizerCallbacks($query, $player)) {
			return;
		}
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
						'Править черновик может только его автор или главный админ.',
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
			case 'ddel':
				$t = $this->tournaments->get($id);
				if (!$this->drafts->canConfirm($t, $player->id)) {
					$this->api->answerCallbackQuery(
						$query->id,
						'Удалить черновик может только его автор или главный админ.',
						true,
					);
					return;
				}
				if ($t->status !== TournamentStatus::DRAFT) {
					$this->api->answerCallbackQuery($query->id, 'Это уже не черновик.', true);
					return;
				}
				$this->api->answerCallbackQuery($query->id);
				$this->api->sendMessage(
					$chat_id,
					'Точно удалить черновик #' . $t->id . '?',
					$query->message_id,
					['reply_markup' => ['inline_keyboard' => [[
						['text' => 'Да', 'callback_data' => 'ddelo:' . $t->id],
						['text' => 'Нет', 'callback_data' => 'ddeln:' . $t->id],
					]]]],
				);
				return;
			case 'ddelo':
				$snap = $this->tournaments->deleteDraft($player->id, $id);
				$this->api->answerCallbackQuery($query->id, 'Удалила');
				$this->api->sendMessage($chat_id, sprintf('Черновик #%d удалён', $snap['id']), $query->message_id);
				return;
			case 'ddeln':
				$this->api->answerCallbackQuery($query->id, 'Оставила');
				return;
			case 'tcancel':
				$t = $this->tournaments->get($id);
				$this->tournaments->assertCanManage($player->id, $t->id);
				if (in_array($t->status, TournamentStatus::past(), true)) {
					$this->api->answerCallbackQuery($query->id, 'Этот турнир уже завершён или отменён.', true);
					return;
				}
				$this->api->answerCallbackQuery($query->id);
				$hint = $t->status === TournamentStatus::DRAFT
					? 'Точно удалить черновик #' . $t->id . '?'
					: 'Точно отменить турнир «' . Html::e($t->title) . '» (№' . $t->id . ')?';
				$this->api->sendMessage(
					$chat_id,
					$hint,
					$query->message_id,
					['reply_markup' => ['inline_keyboard' => [[
						['text' => 'Да, отменить', 'callback_data' => 'tcancely:' . $t->id],
						['text' => 'Нет', 'callback_data' => 'tcanceln:' . $t->id],
					]]]],
				);
				return;
			case 'tcancely':
				$t = $this->tournaments->get($id);
				if ($t->status === TournamentStatus::DRAFT) {
					$snap = $this->tournaments->deleteDraft($player->id, $id);
					$this->api->answerCallbackQuery($query->id, 'Удалила');
					$this->api->sendMessage(
						$chat_id,
						sprintf('Черновик #%d удалён', $snap['id']),
						$query->message_id,
					);
					return;
				}
				$t = $this->tournaments->cancel($player->id, $id);
				$this->announcer->refresh($t->id);
				$this->announcer->renumber($t->id);
				$this->api->answerCallbackQuery($query->id, 'Отменила');
				$this->api->sendMessage(
					$chat_id,
					sprintf('Турнир %s отменён. Жаль!', Html::b($t->title)),
					$query->message_id,
				);
				return;
			case 'tcanceln':
				$this->api->answerCallbackQuery($query->id, 'Оставила');
				return;
			case 'popen':
				$this->open($query, $player, $this->tournaments->get($id));
				return;
			case 'psolo':
				$t = $this->tournaments->get($id);
				$this->teams->joinSolo($t->id, $player->id);
				$this->announcer->refresh($t->id);
				$placement = $this->tournaments->lineup($t->id)->placementOf($player->id);
				$text = $placement === 'reserve'
					? 'Записала тебя соло, но мест уже нет: ты в запасе. Если кто-то снимется, поднимешься сам.'
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
				$captain_id = $entry->team->captain_id;
				$team_name = $entry->team->name;
				$this->teams->leaveTeam($player->id, $entry->team->id);
				$this->announcer->refresh($t->id);
				if ($this->disband_notifier !== null) {
					$this->disband_notifier->notifyMemberLeft($player, $captain_id, $team_name, (int) $t->id);
				}
				$this->done($query, $t, $player, sprintf('Ты вышел из команды «%s».', $team_name));
				return;
			case 'pdissolve':
				$entry = $this->teams->get($id);
				$t = $this->tournaments->get($entry->team->tournament_id);
				$disband = $this->teams->withdrawTeam($player->id, $entry->team->id);
				if ($this->disband_notifier !== null) {
					$this->disband_notifier->notify($player->id, $disband);
				}
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
			if ($t->chat_id !== null) {
			$this->groups->choose($player->id, $t->chat_id);
		}
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
		if (!$this->tournaments->isUserRegistrationOpen($t)) {
			$minutes = $this->tournaments->registrationCloseMinutes();
			$hint = $this->tournaments->isRegistrationTimeLocked($t) && $minutes > 0
				? sprintf(
					'Регистрация закрылась за %d %s до старта — составы уже не меняются.',
					$minutes,
					RuPlural::choose($minutes, ['минуту', 'минуты', 'минут']),
				)
				: 'Регистрация на этот турнир сейчас закрыта.';
			$this->api->answerCallbackQuery($query->id, $hint, true);
			return;
		}
		$existing = $this->teams->teamOf($t->id, $player->id);
		if ($existing !== null) {
			$this->api->answerCallbackQuery($query->id, sprintf('Ты уже в команде «%s».', $existing->team->name), true);
			return;
		}
		if ($this->groups !== null) {
			if ($t->chat_id !== null) {
			$this->groups->choose($player->id, $t->chat_id);
		}
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


	protected function publishedText(Tournament $t): string {
		unset($t);
		return 'Опубликовала! Анонс уже во всех группах, регистрация открыта 🎉';
	}

	/**
		padd/prem/tadd/trem/tcard team-card callbacks.
	*/
	protected function handleTeamAddCallbacks(CallbackQuery $query, Player $player): bool {
		$chat_id = (int) $query->chat_id;
		try {
			if (preg_match('/^padd:(\d+)$/', $query->data, $m) && $this->team_add !== null) {
				$this->api->answerCallbackQuery($query->id);
				$this->team_add->offer($chat_id, $player, (int) $m[1], $query->message_id);
				return true;
			}
			if (preg_match('/^prem:(\d+)$/', $query->data, $m) && $this->team_cards !== null) {
				$entry = $this->teams->get((int) $m[1]);
				$t = $this->tournaments->get($entry->team->tournament_id);
				if (!$this->team_cards->canOfferRemove($t, $entry, $player)) {
					throw new DomainError('Сейчас нельзя удалить игрока из этой команды.');
				}
				$rows = $this->team_cards->removeMemberRows($entry);
				if ($rows === []) {
					throw new DomainError('Кроме капитана в команде никого нет.');
				}
				$this->api->answerCallbackQuery($query->id);
				$this->api->sendMessage(
					$chat_id,
					'Кого убрать из «' . Html::e($entry->team->name) . '»?',
					$query->message_id,
					['reply_markup' => ['inline_keyboard' => $rows]],
				);
				return true;
			}
			if (preg_match('/^trem:(\d+):(\d+)$/', $query->data, $m) && $this->team_cards !== null) {
				$team_id = (int) $m[1];
				$target_id = (int) $m[2];
				$who = $this->playerMention($target_id);
				$entry = $this->teams->removePlayer($player->id, $team_id, $target_id);
				$t = $this->tournaments->get($entry->team->tournament_id);
				$this->announcer->refresh($t->id);
				$this->api->answerCallbackQuery($query->id, 'Убрала');
				$this->api->sendMessage(
					$chat_id,
					'Убрала ' . Html::e($who) . ' из «' . Html::e($entry->team->name) . '».',
					$query->message_id,
				);
				$this->team_cards->send($chat_id, $t, $entry, $player);
				return true;
			}
			if (preg_match('/^tadd:(\d+):(\d+)$/', $query->data, $m) && $this->team_add !== null) {
				$this->api->answerCallbackQuery($query->id, 'Добавляю…');
				$this->team_add->addKnown($chat_id, $player, (int) $m[1], (int) $m[2], $query->message_id);
				return true;
			}
			if (preg_match('/^tcard:(\d+)$/', $query->data, $m) && $this->team_cards !== null) {
				$entry = $this->teams->get((int) $m[1]);
				$t = $this->tournaments->get($entry->team->tournament_id);
				$this->api->answerCallbackQuery($query->id);
				$this->team_cards->send($chat_id, $t, $entry, $player);
				return true;
			}
		} catch (DomainError $e) {
			$this->api->answerCallbackQuery($query->id, $e->getMessage(), true);
			return true;
		}
		return false;
	}


	/**
		oteams/otnew/otren/otcap/otsetc/otdel/otdelo/otcc organizer team-manage callbacks.
	*/
	protected function handleOrganizerCallbacks(CallbackQuery $query, Player $player): bool {
		if ($this->organizer === null) {
			return false;
		}
		$chat_id = (int) $query->chat_id;
		try {
			if (preg_match('/^oteams:(\d+)$/', $query->data, $m)) {
				$t = $this->tournaments->get((int) $m[1]);
				$this->api->answerCallbackQuery($query->id);
				$this->organizer->sendList($chat_id, $t, $player, $query->message_id);
				return true;
			}
			if (preg_match('/^otnew:(\d+)$/', $query->data, $m)) {
				$this->api->answerCallbackQuery($query->id);
				$this->organizer->startCreate($chat_id, $player, (int) $m[1], $query->message_id);
				return true;
			}
			if (preg_match('/^otren:(\d+)$/', $query->data, $m)) {
				$this->api->answerCallbackQuery($query->id);
				$this->organizer->startRename($chat_id, $player, (int) $m[1], $query->message_id);
				return true;
			}
			if (preg_match('/^otcap:(\d+)$/', $query->data, $m)) {
				$this->api->answerCallbackQuery($query->id);
				$this->organizer->offerCaptainPick($chat_id, $player, (int) $m[1], $query->message_id);
				return true;
			}
			if (preg_match('/^otsetc:(\d+):(\d+)$/', $query->data, $m)) {
				$team_id = (int) $m[1];
				$new_cap = (int) $m[2];
				$entry = $this->teams->transferCaptain($player->id, $team_id, $new_cap);
				$t = $this->tournaments->get($entry->team->tournament_id);
				$this->announcer->refresh($t->id);
				$who = $this->playerMention($new_cap);
				$this->api->answerCallbackQuery($query->id, 'Капитан сменён');
				$this->api->sendMessage(
					$chat_id,
					'Новый капитан «' . Html::e($entry->team->name) . '» — ' . Html::e($who) . '.',
					$query->message_id,
				);
				if ($this->team_cards !== null) {
					$this->team_cards->send($chat_id, $t, $entry, $player);
				}
				return true;
			}
			if (preg_match('/^otdel:(\d+)$/', $query->data, $m)) {
				$this->api->answerCallbackQuery($query->id);
				$this->organizer->confirmDelete($chat_id, $player, (int) $m[1], $query->message_id);
				return true;
			}
			if (preg_match('/^otdelo:(\d+)$/', $query->data, $m)) {
				$this->api->answerCallbackQuery($query->id, 'Удаляю…');
				$this->organizer->deleteTeam($chat_id, $player, (int) $m[1], $query->message_id);
				return true;
			}
			if (preg_match('/^otcc:(\d+):(\d+)$/', $query->data, $m)) {
				$this->api->answerCallbackQuery($query->id, 'Создаю…');
				$this->organizer->pickCaptainFromPending(
					$chat_id,
					$player,
					(int) $m[1],
					(int) $m[2],
					$query->message_id,
				);
				return true;
			}
		} catch (DomainError $e) {
			$this->api->answerCallbackQuery($query->id, $e->getMessage(), true);
			return true;
		}
		return false;
	}


	protected function playerMention(int $player_id): string {
		if ($this->players === null) {
			return '#' . $player_id;
		}
		$p = $this->players->find($player_id);
		return $p !== null ? $p->mention() : ('#' . $player_id);
	}

}
