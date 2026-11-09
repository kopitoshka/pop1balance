<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Domain\AccessPolicy;
use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Repository\ChatRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Logger;
use P1Bot\Telegram\ChatAdminSync;
use P1Bot\Telegram\TelegramApi;
use Throwable;

/**
	Groups the bot is in. Tournaments are global; create rights live in CreateAccess.
	Joining no longer requires group membership. players.dm_group_id is remembered for deep links.
*/
class PrivateGroups {
	public const NOT_MEMBER = 'Записаться можно только участникам групп, где я есть. '
		. 'Сначала вступи в одну из групп, потом возвращайся 🙂';

	public function __construct(
		protected ChatRepository $chats,
		protected PlayerRepository $players,
		protected AccessPolicy $access,
		protected ChatAdminSync $admin_sync,
		protected TelegramApi $api,
		protected Logger $logger,
		protected bool $anyone_can_create = false,
		protected ?CreateAccess $create_access = null,
	) {
	}

	/**
		@return array<int, string> chat_id => title of every group the bot is in
	*/
	public function all(): array {
		return $this->chats->listActiveGroups();
	}

	public function title(int $chat_id): string {
		return $this->all()[$chat_id] ?? 'группа';
	}

	/**
		The player's remembered group, or the only group there is; null when a choice is needed.
	*/
	public function current(int $player_id): ?int {
		$groups = $this->all();
		$chosen = $this->players->dmGroup($player_id);
		if ($chosen !== null && isset($groups[$chosen])) {
			return $chosen;
		}
		if (count($groups) === 1) {
			$only = (int) array_key_first($groups);
			$this->players->setDmGroup($player_id, $only);
			return $only;
		}
		return null;
	}

	/**
		Current group or NeedGroupChoice with all groups (legacy deep-link / start g_ flows).
	*/
	public function require(int $player_id): int {
		$current = $this->current($player_id);
		if ($current !== null) {
			return $current;
		}
		$groups = $this->all();
		if ($groups === []) {
			throw new PermissionDenied('Я пока не состою ни в одной группе. Добавь меня в группу турниров.');
		}
		throw new NeedGroupChoice($groups);
	}

	public function choose(int $player_id, int $chat_id): bool {
		if (!isset($this->all()[$chat_id])) {
			return false;
		}
		$this->players->setDmGroup($player_id, $chat_id);
		return true;
	}

	/**
		Whether the player may create tournaments (CreateAccess / override).
	*/
	public function canCreate(int $player_id): bool {
		if ($this->create_access !== null) {
			return $this->create_access->canCreate($player_id);
		}
		return $this->anyone_can_create || $this->access->isOwner($player_id);
	}

	/**
		Origin chat_id for a new tournament (history); null when none / not allowed.
	*/
	public function originForCreate(int $player_id): ?int {
		if ($this->create_access !== null) {
			$this->create_access->assertCanCreate($player_id);
			return $this->create_access->originChatId($player_id);
		}
		$this->access->assertCanCreateTournament(0, $player_id);
		$groups = $this->all();
		return $groups === [] ? null : (int) array_key_first($groups);
	}

	/**
		Admin of at least one group (legacy helpers).
	*/
	public function isAdminSomewhere(int $player_id): bool {
		if ($this->access->isOwner($player_id)) {
			return true;
		}
		foreach (array_keys($this->all()) as $chat_id) {
			$this->admin_sync->syncIfStale($chat_id);
			if ($this->access->isChatAdmin($chat_id, $player_id)) {
				return true;
			}
		}
		return false;
	}

	/**
		Blocks joining when the user is not in any active group the bot is in (getChatMember).
		If Telegram cannot tell for every group (errors), joining is allowed.
	*/
	public function assertMember(?int $chat_id, ?int $tg_user_id): void {
		unset($chat_id);
		$this->assertInAnyGroup($tg_user_id);
	}

	public function assertInAnyGroup(?int $tg_user_id): void {
		if ($tg_user_id === null) {
			return;
		}
		$groups = array_keys($this->all());
		if ($groups === []) {
			return;
		}
		$checked = 0;
		foreach ($groups as $chat_id) {
			try {
				$member = $this->api->getChatMember((int) $chat_id, $tg_user_id);
			} catch (Throwable $e) {
				// Telegram could not tell — skip this group; if none succeed, joining is allowed.
				$this->logger->exception($e, 'getChatMember ' . $chat_id);
				continue;
			}
			$checked++;
			$status = (string) ($member['status'] ?? '');
			if ($status === 'creator' || $status === 'administrator' || $status === 'member') {
				return;
			}
			if ($status === 'restricted' && !empty($member['is_member'])) {
				return;
			}
		}
		if ($checked === 0) {
			return;
		}
		throw new PermissionDenied(self::NOT_MEMBER);
	}

	/**
		@param array<int, string> $groups
		@return array<string, mixed> inline keyboard: one button per group
	*/
	public static function buttons(array $groups): array {
		$rows = [];
		foreach ($groups as $chat_id => $title) {
			$rows[] = [[
				'text' => '👥 ' . ($title !== '' ? $title : (string) $chat_id),
				'callback_data' => 'grp:' . $chat_id,
			]];
		}
		return ['reply_markup' => ['inline_keyboard' => $rows]];
	}
}
