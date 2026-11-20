<?php

declare(strict_types=1);

namespace P1Bot\Handlers;

use P1Bot\App\Announcer;
use P1Bot\App\CreateTournamentForm;
use P1Bot\App\PendingPlayerRunner;
use P1Bot\App\TeamDisbandNotifier;
use P1Bot\App\DraftCards;
use P1Bot\App\PrivateGroups;
use P1Bot\App\PrivateViews;
use P1Bot\App\OrganizerTeams;
use P1Bot\App\PendingTextGuard;
use P1Bot\App\TeamForm;
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
	popen:<t>, grp:<chat id>, tcreatex:0 (dismiss the form offer), pub:<t> / fix:<t>, ddel:<t>
	(delete a draft at once), tcancel/tcancely/tcanceln:<t>, oteams/otnew:<t>, tcard:<team>.
	Old roster buttons (padd/prem/tadd/trem/otren/otcap/otsetc/otdel/otdelo) only send the team
	form. Old group buttons only point to private.
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
		protected ?TeamCards $team_cards = null,
		protected ?PlayerRepository $players = null,
		protected ?OrganizerTeams $organizer = null,
		protected ?PendingTextGuard $pending_guard = null,
		protected ?TeamForm $team_form = null,
	) {
	}

	/**
		For the update log: how the button is treated.
	*/
	public function describe(CallbackQuery $query): string {
		if (str_starts_with($query->data, 'sug:')) {
			return 'suggest pick ' . $query->data;
		}
		if (preg_match('/^(padd|prem|tadd|trem|tcard|oteams|otnew|otren|otcap|otsetc|otdel|otdelo):/', $query->data)) {
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
			case 'tcreatex':
				// «✖️ Отмена» under the /create_tournament form button.
				$this->api->answerCallbackQuery($query->id, 'Отменила');
				if ($query->message_id !== null) {
					$this->api->editMessageText($chat_id, (int) $query->message_id, Html::e(CreateTournamentForm::CANCELLED_TEXT));
				}
				return;
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
				if ($t->status !== TournamentStatus::DRAFT) {
					$this->api->answerCallbackQuery($query->id, 'Это уже не черновик.', true);
					return;
				}
				if ($this->drafts->offerEdit($chat_id, $t, $player, $query->message_id)) {
					$this->api->answerCallbackQuery($query->id);
				} else {
					$this->api->answerCallbackQuery(
						$query->id,
						'Форма правки сейчас недоступна, попробуй чуть позже.',
						true,
					);
				}
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
				$this->deleteDraft($query, $player, $id);
				return;
			case 'ddelo':
				$this->deleteDraft($query, $player, $id);
				return;
			case 'ddeln':
				$this->keepTournament($query, $player, $id);
				return;
			case 'tcancel':
				$t = $this->tournaments->get($id);
				$this->tournaments->assertCanManage($player->id, $t->id);
				if (in_array($t->status, TournamentStatus::past(), true)) {
					$this->api->answerCallbackQuery($query->id, 'Этот турнир уже завершён или отменён.', true);
					return;
				}
				if ($t->status === TournamentStatus::DRAFT) {
					$this->deleteDraft($query, $player, $id);
					return;
				}
				$this->api->answerCallbackQuery($query->id);
				$hint = 'Точно отменить турнир «' . Html::e($t->title) . '» (№' . $t->id . ')?';
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
				$this->keepTournament($query, $player, $id);
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
		Old «Собрать команду» cards now send a form, never a text question.
	*/
	protected function askTeam(CallbackQuery $query, Player $player, Tournament $t): void {
		$entry = $this->teams->teamOf($t->id, $player->id);
		$this->offerTeamForm($query, $player, $t, $entry?->team->id);
	}

	protected function offerTeamForm(CallbackQuery $query, Player $player, Tournament $t, ?int $team_id): void {
		$button = $this->team_form?->button($player, (int) $query->chat_id, $t->id, $team_id);
		$this->api->answerCallbackQuery($query->id);
		$this->api->sendMessage(
			(int) $query->chat_id,
			$button === null ? TeamForm::UNAVAILABLE_TEXT : 'Открой форму команды:',
			$query->message_id,
			$button === null ? [] : ['reply_markup' => ['inline_keyboard' => [[$button]]]],
		);
	}

	protected function deleteDraft(CallbackQuery $query, Player $player, int $id): void {
		$snap = $this->tournaments->deleteDraft($player->id, $id);
		$this->api->answerCallbackQuery($query->id, 'Удалила');
		$this->api->sendMessage((int) $query->chat_id, sprintf('Черновик #%d удалён', $snap['id']), $query->message_id);
	}

	protected function keepTournament(CallbackQuery $query, Player $player, int $id): void {
		$t = $this->tournaments->get($id);
		$this->tournaments->assertCanManage($player->id, $t->id);
		$this->api->answerCallbackQuery($query->id);
		if ($query->message_id !== null) {
			// Same question, no buttons: the choice is made.
			try {
				$this->api->editMessageText(
					(int) $query->chat_id,
					$query->message_id,
					'Точно отменить турнир «' . Html::e($t->title) . '» (№' . $t->id . ')?',
				);
			} catch (\Throwable $e) {
				// Too old to edit: the message below is enough.
			}
		}
		$this->api->sendMessage((int) $query->chat_id, 'Оставила');
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
		Old roster buttons never mutate a team. Send its new form instead.
	*/
	protected function handleTeamAddCallbacks(CallbackQuery $query, Player $player): bool {
		if (!preg_match('/^(?:padd|prem|tadd|trem|tcard|otren|otcap|otsetc|otdel|otdelo):(\d+)(?::\d+)?$/', $query->data, $m)) {
			return false;
		}
		try {
			$entry = $this->teams->get((int) $m[1]);
			$t = $this->tournaments->get($entry->team->tournament_id);
			if ($this->team_form !== null && $this->team_form->role($player, $t, $entry) === 'stranger') {
				throw new DomainError(TeamForm::STRANGER_TEXT);
			}
			if (str_starts_with($query->data, 'tcard:') && $this->team_cards !== null) {
				$this->api->answerCallbackQuery($query->id);
				$this->team_cards->send((int) $query->chat_id, $t, $entry, $player);
			} else {
				$this->offerTeamForm($query, $player, $t, $entry->team->id);
			}
		} catch (DomainError $e) {
			$this->api->answerCallbackQuery($query->id, $e->getMessage(), true);
		}
		return true;
	}

	/**
		Organizer list and the old «new team» button.
	*/
	protected function handleOrganizerCallbacks(CallbackQuery $query, Player $player): bool {
		if (!preg_match('/^(oteams|otnew):(\d+)$/', $query->data, $m) || $this->organizer === null) {
			return false;
		}
		try {
			$t = $this->tournaments->get((int) $m[2]);
			if (!$this->organizer->canManage($t, $player)) {
				throw new DomainError('Команды турнира правит только организатор или главный админ.');
			}
			if ($m[1] === 'oteams') {
				$this->api->answerCallbackQuery($query->id);
				$this->organizer->sendList((int) $query->chat_id, $t, $player, $query->message_id);
			} else {
				$this->offerTeamForm($query, $player, $t, null);
			}
		} catch (DomainError $e) {
			$this->api->answerCallbackQuery($query->id, $e->getMessage(), true);
		}
		return true;
	}
}
