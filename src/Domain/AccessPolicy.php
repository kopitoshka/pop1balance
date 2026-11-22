<?php

declare(strict_types=1);

namespace P1Bot\Domain;

use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Domain\Model\Player;
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
		Only a superadmin (the bot owner, or a player with the /su right and the mode on) changes player
		levels and picks a tournament's scale; everyone else always uses the default scale. Scale
		tables and columns stay, so this can be turned back on.
	*/
	public const LEVELS_OWNER_ONLY = 'Уровни игроков меняет только главный админ.';
	public const LEVEL_SELF = 'Уровень ставит админ.';
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
		Is this player the bot author (BOT_OWNER_TG_ID)? Identity only, no mode. Use it where the
		person matters (protecting their card, the /su rules), never as a permission gate: gates
		use isSuper().
	*/
	public function isBotOwner(int $player_id): bool {
		return $this->owner_tg_id !== 0 && $this->players->find($player_id)?->tg_user_id === $this->owner_tg_id;
	}

	/**
		The bot author's player row, if the bot has seen them.
	*/
	public function botOwnerPlayer(): ?Player {
		return $this->owner_tg_id === 0 ? null : $this->players->findByTelegramId($this->owner_tg_id);
	}

	/**
		May this player switch superadmin mode on: the bot owner (always) or a player with the right.
	*/
	public function hasSuRight(int $player_id): bool {
		return $this->suState($player_id)['right'];
	}

	/**
		Is the superadmin mode on for this player? Stored per player; without a stored value it is
		on for the bot owner and off for everyone else. False without the right.
	*/
	public function suModeOn(int $player_id): bool {
		$state = $this->suState($player_id);
		return $state['right'] && $state['mode'];
	}

	/**
		THE gate for every bot-owner ability: the bot owner with the mode on, or a player with the
		right and the mode on. Levels, nicks of others, the player card, /players_import,
		editing and cancelling any tournament, owner-only buttons and hints all ask this.
	*/
	public function isSuper(int $player_id): bool {
		return $this->suModeOn($player_id);
	}

	/**
		May this player /su grant, /su revoke and /su list (and see the right on the player card):
		the bot owner always, even with the mode off; others only with the right and the mode on.
	*/
	public function canManageSu(int $player_id): bool {
		return $this->isBotOwner($player_id) || $this->isSuper($player_id);
	}

	/**
		@return array{right: bool, mode: bool}
	*/
	protected function suState(int $player_id): array {
		$player = $this->players->find($player_id);
		if ($player === null || $player->isDeleted()) {
			return ['right' => false, 'mode' => false];
		}
		$owner = $this->owner_tg_id !== 0 && $player->tg_user_id === $this->owner_tg_id;
		return [
			'right' => $owner || $player->can_su,
			'mode' => $player->su_mode ?? $owner,
		];
	}

	public function assertSuper(int $player_id, string $message = self::OWNER_ONLY): void {
		if (!$this->isSuper($player_id)) {
			throw new PermissionDenied($message);
		}
	}

	public function isChatAdmin(int $chat_id, int $player_id): bool {
		return $this->isSuper($player_id) || $this->admins->isAdmin($chat_id, $player_id);
	}

	public function assertChatAdmin(int $chat_id, int $player_id): void {
		if (!$this->isChatAdmin($chat_id, $player_id)) {
			throw new PermissionDenied('Это могут делать только админы чата.');
		}
	}

	/**
		Legacy helper: ANYONE_CAN_CREATE_TOURNAMENT=1 or a superadmin.
		Real create gate is CreateAccess (shared active group with the owner); handlers use that.
		@param int $chat_id unused (kept for call-site compatibility)
	*/
	public function assertCanCreateTournament(int $chat_id, int $player_id): void {
		unset($chat_id);
		if ($this->anyone_can_create_tournament || $this->isSuper($player_id)) {
			return;
		}
		throw new PermissionDenied(
			'Создавать турниры могут только участники групп, где есть организатор P1. '
			. 'Записаться на турнир при этом можно 🙂',
		);
	}

	public function anyoneCanCreateTournament(): bool {
		return $this->anyone_can_create_tournament;
	}

	/**
		Edit / open / cancel: only the tournament's author (organizer) or a superadmin (isSuper).
		Group admins have no special rights here.
	*/
	public function canManageTournament(Tournament $tournament, int $player_id): bool {
		return $tournament->organizer_id === $player_id || $this->isSuper($player_id);
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
