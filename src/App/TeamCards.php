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
use P1Bot\View\AnnouncementView;
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
		protected ?TeamForm $form = null,
	) {
	}

	public function setForm(?TeamForm $form): void {
		$this->form = $form;
	}

	/**
		HTML body of a team card (no buttons).
	*/
	public function text(Tournament $t, TeamEntry $entry): string {
		$lines = [
			'🛡 ' . Html::e(($entry->team->letter ?? '?') . '. ') . '<b>' . Html::e($entry->team->name) . '</b>',
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
			$points = $this->teams->pointsOf($entry);
			$limit = (int) $t->max_points;
			$over = $points > $limit;
			$lines[] = '⚖️ Баллы: ' . Html::e(AnnouncementView::pointsLabel($points, $limit, $over));
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
		One «✏️ Редактировать команду» button: the form is editable for captain/organizer/owner
		and read-only (with «❌ Отменить регистрацию») for other members.
		Without a Mini App URL: only leave / disband buttons.

		@return list<list<array<string, mixed>>>
	*/
	public function rows(Tournament $t, TeamEntry $entry, Player $actor, ?int $chat_id = null): array {
		if (!in_array($t->status, [TournamentStatus::REGISTRATION, TournamentStatus::CLOSED], true)) {
			return [];
		}
		$is_captain = $entry->team->captain_id === $actor->id;
		$is_member = false;
		foreach ($entry->members as $m) {
			$is_member = $is_member || $m->player_id === $actor->id;
		}
		if (!$is_member && !$this->access->canManageTournament($t, $actor->id)) {
			return [];
		}
		$button = $this->form?->button($actor, $chat_id ?? (int) $actor->tg_user_id, $t->id, $entry->team->id);
		if ($button !== null) {
			return [[$button]];
		}
		if ($is_captain) {
			return [[[
				'text' => '🚫 Распустить команду «' . $entry->team->name . '»',
				'callback_data' => 'pdissolve:' . $entry->team->id,
			]]];
		}
		if ($is_member) {
			return [[[
				'text' => '❌ Отменить регистрацию',
				'callback_data' => 'pleave:' . $entry->team->id,
			]]];
		}
		return [];
	}

	/**
		Send one team card; returns last message id.
	*/
	public function send(int $chat_id, Tournament $t, TeamEntry $entry, Player $actor, ?int $reply_to = null): int {
		$markup = PrivateViews::keyboard($this->rows($t, $entry, $actor, $chat_id));
		return $this->api->sendMessage($chat_id, $this->text($t, $entry), $reply_to, $markup);
	}
}
