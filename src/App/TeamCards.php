<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Domain\AccessPolicy;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\Model\TeamEntry;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\TeamService;
use P1Bot\Domain\TournamentService;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Telegram\TelegramApi;
use P1Bot\View\Html;

/**
	Private-chat team card: roster, points, manage buttons (add / remove / leave / dissolve).
*/
class TeamCards {

	public function __construct(
		protected TelegramApi $api,
		protected TournamentService $tournaments,
		protected TeamService $teams,
		protected PlayerRepository $players,
		protected AccessPolicy $access,
		protected ?PrivateGroups $groups = null,
		protected ?OrganizerTeams $organizer = null,
	) {
	}

	public function setOrganizer(?OrganizerTeams $organizer): void {
		$this->organizer = $organizer;
	}

	/**
		HTML body of a team card (no buttons).
	*/
	public function text(Tournament $t, TeamEntry $entry): string {
		$lines = [
			'🛡 <b>' . Html::e($entry->team->name) . '</b>',
			'🏆 ' . Html::e($t->title) . ' · №' . $t->id,
		];
		if ($this->groups !== null && $t->chat_id !== null && count($this->groups->all()) > 1) {
			$lines[] = '👥 ' . Html::e($this->groups->title($t->chat_id));
		}
		$lines[] = sprintf(
			'👥 %d/%d · статус: %s',
			$entry->size(),
			$t->team_size,
			$entry->team->isRegistered() ? 'записана' : 'собирается',
		);
		if ($t->hasLevelLimit()) {
			$lines[] = sprintf('⚖️ Баллы: %d / %d', $this->teams->pointsOf($entry), (int) $t->max_points);
		}
		$lines[] = '';
		foreach ($entry->members as $member) {
			$player = $this->players->find($member->player_id);
			$label = $player !== null ? $player->lineupLabel() : ('#' . $member->player_id);
			$prefix = $member->player_id === $entry->team->captain_id ? '👑 ' : '• ';
			$lines[] = $prefix . $label;
		}
		return implode("\n", $lines);
	}

	/**
		@return list<list<array<string, mixed>>>
	*/
	public function rows(Tournament $t, TeamEntry $entry, Player $actor): array {
		if (!in_array($t->status, [TournamentStatus::REGISTRATION, TournamentStatus::CLOSED], true)) {
			return [];
		}
		$rows = [];
		$is_captain = $entry->team->captain_id === $actor->id;
		$is_member = $this->teams->teamOf($t->id, $actor->id)?->team->id === $entry->team->id;

		if ($this->canOfferAdd($t, $entry, $actor)) {
			$rows[] = [[
				'text' => '➕ Добавить игрока',
				'callback_data' => 'padd:' . $entry->team->id,
			]];
		}
		if ($this->canOfferRemove($t, $entry, $actor)) {
			$rows[] = [[
				'text' => '➖ Удалить игрока',
				'callback_data' => 'prem:' . $entry->team->id,
			]];
		}
		if ($is_captain) {
			$rows[] = [[
				'text' => '🚫 Распустить команду «' . $entry->team->name . '»',
				'callback_data' => 'pdissolve:' . $entry->team->id,
			]];
		} elseif ($is_member) {
			$rows[] = [[
				'text' => '❌ Отменить регистрацию',
				'callback_data' => 'pleave:' . $entry->team->id,
			]];
		}
		if ($this->organizer !== null) {
			foreach ($this->organizer->manageRows($t, $entry, $actor) as $row) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	public function canOfferAdd(Tournament $t, TeamEntry $entry, Player $actor): bool {
		unset($t);
		if ($entry->size() >= $this->tournaments->get($entry->team->tournament_id)->team_size) {
			return false;
		}
		return $this->teams->canActorAddPlayer($actor->id, $entry->team->id);
	}

	public function canOfferRemove(Tournament $t, TeamEntry $entry, Player $actor): bool {
		unset($t);
		$removable = 0;
		foreach ($entry->members as $member) {
			if ($member->player_id !== $entry->team->captain_id) {
				$removable++;
			}
		}
		if ($removable === 0) {
			return false;
		}
		return $this->teams->canActorRemovePlayer($actor->id, $entry->team->id);
	}

	/**
		Inline buttons: members except captain.
		@return list<list<array<string, string>>>
	*/
	public function removeMemberRows(TeamEntry $entry): array {
		$rows = [];
		foreach ($entry->members as $member) {
			if ($member->player_id === $entry->team->captain_id) {
				continue;
			}
			$player = $this->players->find($member->player_id);
			$label = $player !== null ? $player->plainLabel() : ('#' . $member->player_id);
			$rows[] = [[
				'text' => '➖ ' . mb_substr($label, 0, 60),
				'callback_data' => 'trem:' . $entry->team->id . ':' . $member->player_id,
			]];
		}
		return $rows;
	}

	/**
		Send one team card; returns last message id.
	*/
	public function send(int $chat_id, Tournament $t, TeamEntry $entry, Player $actor, ?int $reply_to = null): int {
		$markup = PrivateViews::keyboard($this->rows($t, $entry, $actor));
		return $this->api->sendMessage($chat_id, $this->text($t, $entry), $reply_to, $markup);
	}
}
