<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Domain\AccessPolicy;
use P1Bot\Domain\Exception\DomainError;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\Model\TeamEntry;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\TeamService;
use P1Bot\Domain\TournamentService;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Repository\TeamRepository;
use P1Bot\Telegram\TelegramApi;
use P1Bot\View\AnnouncementView;
use P1Bot\View\Html;

/**
	Organizer/owner private list «👥 Команды» of a tournament. Each team button opens the team
	form (TeamForm) in edit mode, «➕ Добавить команду» opens it in new mode. Renaming,
	captain change, letters and disbanding all happen inside the form.
*/
class OrganizerTeams {

	public function __construct(
		protected TelegramApi $api,
		protected TournamentService $tournaments,
		protected TeamService $teams,
		protected TeamRepository $team_repo,
		protected AccessPolicy $access,
		protected PlayerRepository $players,
		protected ?TeamForm $form = null,
	) {
	}

	public function setForm(?TeamForm $form): void {
		$this->form = $form;
	}

	protected function membersLine(TeamEntry $entry): string {
		$captain = [];
		$others = [];
		foreach ($entry->members as $member) {
			$player = $this->players->find($member->player_id);
			$label = $player !== null ? $player->lineupLabel() : ('#' . $member->player_id);
			if ($member->player_id === $entry->team->captain_id) {
				$captain[] = '👑 ' . $label;
			} else {
				$others[] = $label;
			}
		}
		return implode(', ', array_merge($captain, $others));
	}

	public function canManage(Tournament $t, Player $actor): bool {
		return in_array($t->status, [TournamentStatus::REGISTRATION, TournamentStatus::CLOSED], true)
			&& $this->access->canManageTournament($t, $actor->id);
	}

	public function sendList(int $chat_id, Tournament $t, Player $actor, ?int $reply_to = null): void {
		if (!$this->canManage($t, $actor)) {
			throw new DomainError('Команды турнира правит только организатор или главный админ.');
		}
		$lineup = $this->tournaments->lineup($t->id);
		$teams = $this->team_repo->listByTournament($t->id);
		$lines = ['👥 <b>Команды</b> · ' . Html::e($t->title) . ' · №' . $t->id];
		$rows = [];
		if ($teams === []) {
			$lines[] = '';
			$lines[] = 'Пока нет ни одной команды.';
		}
		foreach ($teams as $team) {
			$entry = $this->teams->get($team->id);
			$place = $lineup->placementOf($team->captain_id) ?? 'forming';
			$status = match ($place) {
				'main' => 'в сетке',
				'reserve' => 'запас',
				default => 'собирается',
			};
			$points = $this->teams->pointsOf($entry);
			$limit = $t->hasLevelLimit() ? (int) $t->max_points : null;
			$over = $limit !== null && $points > $limit;
			$letter = $team->letter ?? '?';
			if ($place === 'forming') {
				$status = 'собирается ' . $entry->size() . '/' . $t->team_size;
			}
			$lines[] = sprintf(
				'%s. %s · %s · %s',
				$letter,
				Html::e($team->name),
				Html::e($status),
				Html::e(AnnouncementView::pointsLabel($points, $limit, $over)),
			);
			$lines[] = $this->membersLine($entry);
			$btn = $letter . '. 🛡 ' . $team->name . ' · ' . $entry->size() . '/' . $t->team_size;
			if ($over) {
				$btn .= ' ⚠️';
			}
			$text = mb_substr($btn, 0, 64);
			$button = $this->form?->button($actor, $chat_id, $t->id, $team->id, null, $text);
			$rows[] = [$button ?? ['text' => $text, 'callback_data' => 'tcard:' . $team->id]];
		}
		$add = $this->form?->button($actor, $chat_id, $t->id, null, null, '➕ Добавить команду');
		if ($add !== null) {
			$rows[] = [$add];
		}
		$this->api->sendMessage(
			$chat_id,
			implode("\n", $lines),
			$reply_to,
			['reply_markup' => ['inline_keyboard' => $rows]],
		);
	}
}
