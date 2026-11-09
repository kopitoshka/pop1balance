<?php

declare(strict_types=1);

namespace P1Bot\App;

use DateTimeImmutable;
use P1Bot\Domain\AccessPolicy;
use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Repository\ChatRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Clock;
use P1Bot\Support\Logger;
use P1Bot\Telegram\TelegramApi;
use Throwable;

/**
	Who may create a global tournament: the bot owner; anyone when ANYONE_CAN_CREATE_TOURNAMENT;
	otherwise a player who shares at least one active group with both the bot and the owner
	(getChatMember: member / administrator / creator / restricted-but-member).
*/
class CreateAccess {
	public const REFUSAL = 'Создавать турниры могут только участники групп, где есть организатор P1. '
		. 'Записаться на турнир при этом можно 🙂';

	/**
		How long a getChatMember result is reused (seconds).
	*/
	protected const CACHE_TTL = 600;

	/**
		@var array<string, array{ok: bool, until: DateTimeImmutable}>
	*/
	protected array $member_cache = [];

	public function __construct(
		protected TelegramApi $api,
		protected ChatRepository $chats,
		protected PlayerRepository $players,
		protected AccessPolicy $access,
		protected Clock $clock,
		protected Logger $logger,
		protected int $owner_tg_id,
		protected bool $anyone_can_create = false,
	) {
	}

	public function canCreate(int $player_id): bool {
		if ($this->anyone_can_create || $this->access->isOwner($player_id)) {
			return true;
		}
		return $this->originChatId($player_id) !== null;
	}

	public function assertCanCreate(int $player_id): void {
		if (!$this->canCreate($player_id)) {
			throw new PermissionDenied(self::REFUSAL);
		}
	}

	/**
		A group to store as tournaments.chat_id (origin/history). Null when the player may not create
		and ANYONE_CAN_CREATE is off. When they may create but there are no groups yet, returns null
		(caller still creates with nullable chat_id).
	*/
	public function originChatId(int $player_id): ?int {
		$groups = $this->chats->listActiveGroups();
		if ($groups === []) {
			return null;
		}
		if ($this->anyone_can_create || $this->access->isOwner($player_id)) {
			return (int) array_key_first($groups);
		}
		if ($this->owner_tg_id === 0) {
			return null;
		}
		$user_tg = $this->players->find($player_id)?->tg_user_id;
		if ($user_tg === null) {
			return null;
		}
		foreach (array_keys($groups) as $chat_id) {
			if ($this->isPresent((int) $chat_id, $this->owner_tg_id)
				&& $this->isPresent((int) $chat_id, $user_tg)
			) {
				return (int) $chat_id;
			}
		}
		return null;
	}

	/**
		True when Telegram reports the user as present in the group.
	*/
	public function isPresent(int $chat_id, int $tg_user_id): bool {
		$key = $chat_id . ':' . $tg_user_id;
		$now = $this->clock->now();
		$cached = $this->member_cache[$key] ?? null;
		if ($cached !== null && $cached['until'] > $now) {
			return $cached['ok'];
		}
		$ok = $this->fetchPresent($chat_id, $tg_user_id);
		$this->member_cache[$key] = [
			'ok' => $ok,
			'until' => $now->modify('+' . self::CACHE_TTL . ' seconds'),
		];
		return $ok;
	}

	public function clearCache(): void {
		$this->member_cache = [];
	}

	protected function fetchPresent(int $chat_id, int $tg_user_id): bool {
		try {
			$member = $this->api->getChatMember($chat_id, $tg_user_id);
		} catch (Throwable $e) {
			$this->logger->exception($e, 'getChatMember ' . $chat_id . '/' . $tg_user_id);
			return false;
		}
		$status = (string) ($member['status'] ?? '');
		if ($status === 'creator' || $status === 'administrator' || $status === 'member') {
			return true;
		}
		return $status === 'restricted' && !empty($member['is_member']);
	}
}
