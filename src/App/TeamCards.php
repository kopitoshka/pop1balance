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
	Private-chat team card: roster, points, manage buttons (add / leave / dissolve).
*/
class TeamCards {

	public function __construct(
		protected TelegramApi $api,
		protected TournamentService $tournaments,
		protected TeamService $teams,
		protected PlayerRepository $players,
		protected AccessPolicy $access,
		protected ?PrivateGroups $groups = null,
	) {
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
			$suffix = $member->player_id === $entry->team->captain_id ? ' <i>(кэп)</i>' : '';
			$lines[] = $prefix . $label . $suffix;
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
		if ($this->canOfferAdd($t, $entry, $actor)) {
			$rows[] = [[
				'text' => '➕ Добавить игрока',
				'callback_data' => 'padd:' . $entry->team->id,
			]];
		}
		if ($entry->team->captain_id === $actor->id) {
			$rows[] = [[
				'text' => '🚫 Распустить команду «' . $entry->team->name . '»',
				'callback_data' => 'pdissolve:' . $entry->team->id,
			]];
		} elseif ($this->teams->teamOf($t->id, $actor->id)?->team->id === $entry->team->id) {
			$rows[] = [[
				'text' => '🚪 Выйти из команды «' . $entry->team->name . '»',
				'callback_data' => 'pleave:' . $entry->team->id,
			]];
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

	/**
		Send one team card; returns last message id.
	*/
	public function send(int $chat_id, Tournament $t, TeamEntry $entry, Player $actor, ?int $reply_to = null): int {
		$markup = PrivateViews::keyboard($this->rows($t, $entry, $actor));
		return $this->api->sendMessage($chat_id, $this->text($t, $entry), $reply_to, $markup);
	}
}
