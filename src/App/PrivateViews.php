<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Domain\AccessPolicy;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\TeamService;
use P1Bot\Domain\TournamentService;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Telegram\TelegramApi;
use P1Bot\View\Html;

/**
	What the private chat shows: a tournament with buttons for the player's state and the list of
	tournaments with «open» buttons. Tournament card: psolo/punsolo/pteam, «🛡 Моя команда» (tcard),
	«👥 Команды» (oteams), «❌ Отменить турнир» (tcancel) for author/owner. Team manage on TeamCards.
*/
class PrivateViews {

	public function __construct(
		protected TelegramApi $api,
		protected TournamentService $tournaments,
		protected TeamService $teams,
		protected Announcer $announcer,
		protected TournamentListing $listing,
		protected PrivateGroups $groups,
		protected AccessPolicy $access,
		protected ?TeamCards $team_cards = null,
	) {
	}

	/**
		Sends the full tournament view; the last message carries the buttons.

		@return int id of the last message
	*/
	public function tournament(int $chat_id, Tournament $t, Player $player, ?int $reply_to = null): int {
		$messages = $this->announcer->infoMessages($t);
		if (count($this->groups->all()) > 1) {
			$messages[0] = '👥 Группа <b>' . Html::e($this->groups->title($t->chat_id))
				. "</b>\n\n" . $messages[0];
		}
		$message_id = 0;
		foreach ($messages as $i => $text) {
			$last = $i === count($messages) - 1;
			$message_id = $this->api->sendMessage($chat_id, $text, $reply_to, $last ? $this->buttons($t, $player) : []);
		}
		return $message_id;
	}

	/**
		Buttons for the player's state in the tournament.

		@return array<string, mixed>
	*/
	public function buttons(Tournament $t, Player $player): array {
		return self::keyboard($this->rows($t, $player));
	}

	/**
		@return list<list<array<string, string>>>
	*/
	public function rows(Tournament $t, Player $player): array {
		if (in_array($t->status, TournamentStatus::past(), true)) {
			return [];
		}
		$can_manage = $this->access->canManageTournament($t, $player->id);
		$rows = [];
		if (in_array($t->status, TournamentStatus::active(), true)) {
			$time_locked = $this->tournaments->isRegistrationTimeLocked($t);
			// Time lock: regular players cannot change lineups; organizer/owner still can.
			if (!($time_locked && !$can_manage)) {
				// Tournament info card: only tournament-level actions. Team manage lives on team cards.
				$team = $this->teams->teamOf($t->id, $player->id);
				if ($team !== null) {
					$rows[] = [[
						'text' => '🛡 Моя команда «' . mb_substr($team->team->name, 0, 40) . '»',
						'callback_data' => 'tcard:' . $team->team->id,
					]];
				} elseif (($placement = $this->tournaments->lineup($t->id)->placementOf($player->id)) !== null) {
					$rows[] = [['text' => '❌ Отписаться', 'callback_data' => 'punsolo:' . $t->id]];
				} elseif ($this->tournaments->isUserRegistrationOpen($t)) {
					$rows[] = [['text' => '🙋 Записаться соло', 'callback_data' => 'psolo:' . $t->id]];
					$rows[] = [['text' => '👥 Собрать команду', 'callback_data' => 'pteam:' . $t->id]];
				}
			}
			if (
				$can_manage
				&& in_array($t->status, [TournamentStatus::REGISTRATION, TournamentStatus::CLOSED], true)
			) {
				$rows[] = [['text' => '👥 Команды', 'callback_data' => 'oteams:' . $t->id]];
			}
		}
		if ($can_manage) {
			$rows[] = [['text' => '❌ Отменить турнир', 'callback_data' => 'tcancel:' . $t->id]];
		}
		return $rows;
	}

	/**
		The list of all groups' tournaments with «open» buttons; $intro goes first (greeting).

		@return int id of the last message
	*/
	public function list(int $chat_id, Player $player, bool $is_owner, string $intro = ''): int {
		$groups = $this->groups->all();
		$messages = $this->listing->privateMessages($groups, $player->id, $is_owner);
		if ($messages === []) {
			$messages = [($intro !== '' ? $intro . "\n\n" : '') . 'Сейчас активных турниров нет. Как только появятся — анонс будет в группе.'];
		} elseif ($intro !== '') {
			array_unshift($messages, $intro);
		}
		$buttons = TournamentListing::openButtons(
			$this->listing->privateTournaments($groups, $player->id, $is_owner),
			$groups,
		);
		$message_id = 0;
		foreach ($messages as $i => $text) {
			$last = $i === count($messages) - 1;
			$message_id = $this->api->sendMessage($chat_id, $text, null, $last ? $buttons : []);
		}
		return $message_id;
	}

	/**
		@param list<list<array<string, string>>> $rows
		@return array<string, mixed>
	*/
	public static function keyboard(array $rows): array {
		return $rows === [] ? [] : ['reply_markup' => ['inline_keyboard' => $rows]];
	}
}
