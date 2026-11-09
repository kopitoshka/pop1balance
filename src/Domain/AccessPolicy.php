<?php

declare(strict_types=1);

namespace P1Bot\Domain;

use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Domain\Model\Team;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Repository\AdminRepository;
use P1Bot\Repository\PlayerRepository;

/**
	All permission rules in one place. The AI layer never decides permissions.
*/
class AccessPolicy {
	/*
		Temporary product decision (Nov 2026): rating scales («шкалы», «балансы») are hidden from users.
		Only the bot owner changes player levels and picks a tournament's scale; everyone else always
		uses the owner's default scale. Scale tables and columns stay, so this can be turned back on.
	*/
	public const LEVELS_OWNER_ONLY = 'Уровни игроков меняет только главный админ.';
	public const OWNER_ONLY = 'Это доступно только главному админу.';
	public const MANAGE_ONLY = 'Менять турнир может только его автор или главный админ.';

	public function __construct(
		protected AdminRepository $admins,
		protected PlayerRepository $players,
		protected int $owner_tg_id,
		protected bool $anyone_can_create_tournament = false,
	) {
	}

	/**
		Bot author (BOT_OWNER_TG_ID).
	*/
	public function isOwner(int $player_id): bool {
		if ($this->owner_tg_id === 0) {
			return false;
		}
		return $this->players->find($player_id)?->tg_user_id === $this->owner_tg_id;
	}

	public function assertOwner(int $player_id, string $message = self::OWNER_ONLY): void {
		if (!$this->isOwner($player_id)) {
			throw new PermissionDenied($message);
		}
	}

	public function isChatAdmin(int $chat_id, int $player_id): bool {
		return $this->isOwner($player_id) || $this->admins->isAdmin($chat_id, $player_id);
	}

	public function assertChatAdmin(int $chat_id, int $player_id): void {
		if (!$this->isChatAdmin($chat_id, $player_id)) {
			throw new PermissionDenied('Это могут делать только админы чата.');
		}
	}

	/**
		Who may create a tournament in a group: chat admins (and the owner), unless
		ANYONE_CAN_CREATE_TOURNAMENT is on — then any player.
	*/
	public function assertCanCreateTournament(int $chat_id, int $player_id): void {
		if (!$this->anyone_can_create_tournament) {
			$this->assertChatAdmin($chat_id, $player_id);
		}
	}

	/**
		Edit / open / close / cancel: only the tournament's author (organizer) or the bot owner.
		Group admins have no special rights here.
	*/
	public function canManageTournament(Tournament $tournament, int $player_id): bool {
		return $tournament->organizer_id === $player_id || $this->isOwner($player_id);
	}

	public function assertCanManageTournament(Tournament $tournament, int $player_id): void {
		if (!$this->canManageTournament($tournament, $player_id)) {
			throw new PermissionDenied(self::MANAGE_ONLY);
		}
	}

	public function assertCaptain(Team $team, int $player_id): void {
		if ($team->captain_id !== $player_id) {
			throw new PermissionDenied(sprintf('Состав команды «%s» меняет только капитан.', $team->name));
		}
	}
}
