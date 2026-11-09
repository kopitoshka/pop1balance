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
	Which group a private conversation creates tournaments in. Tournament numbers are global (ids);
	the player's current group (players.dm_group_id) is remembered for /new and membership checks.
	With a single group it is chosen automatically; with several, forCreate asks via inline buttons.
*/
class PrivateGroups {
	public const NOT_MEMBER = 'Записаться можно только участникам группы турнира. Сначала вступи в группу, потом возвращайся 🙂';

	public function __construct(
		protected ChatRepository $chats,
		protected PlayerRepository $players,
		protected AccessPolicy $access,
		protected ChatAdminSync $admin_sync,
		protected TelegramApi $api,
		protected Logger $logger,
		protected bool $anyone_can_create = false,
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
		The player's current group, or the only group there is; null when a choice is needed.
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
		Current group or NeedGroupChoice with all groups.
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
		Groups where the player may create tournaments: where they are a chat admin (owner: all;
		ANYONE_CAN_CREATE_TOURNAMENT: all groups the bot is in).

		@return array<int, string>
	*/
	public function creatable(int $player_id): array {
		$groups = $this->all();
		if ($this->anyone_can_create || $this->access->isOwner($player_id)) {
			return $groups;
		}
		$result = [];
		foreach ($groups as $chat_id => $title) {
			$this->admin_sync->syncIfStale($chat_id);
			if ($this->access->isChatAdmin($chat_id, $player_id)) {
				$result[$chat_id] = $title;
			}
		}
		return $result;
	}

	/**
		Group for a new tournament: the current one when allowed, else the only allowed one,
		else NeedGroupChoice. Switches the player's current group to it.
	*/
	public function forCreate(int $player_id): int {
		$allowed = $this->creatable($player_id);
		if ($allowed === []) {
			throw new PermissionDenied('Создавать турниры могут только админы группы.');
		}
		$current = $this->players->dmGroup($player_id);
		if ($current !== null && isset($allowed[$current])) {
			return $current;
		}
		if (count($allowed) === 1) {
			$only = (int) array_key_first($allowed);
			$this->players->setDmGroup($player_id, $only);
			return $only;
		}
		throw new NeedGroupChoice($allowed, 'В какой группе создаём турнир? Выбери кнопкой ниже и повтори запрос.');
	}

	/**
		Admin of at least one group (admin tools are offered; every action re-checks per tournament).
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
		Blocks joining a tournament for users who are not in its group (getChatMember).
		If Telegram cannot tell (error, bot without rights), joining is allowed.
	*/
	public function assertMember(int $chat_id, ?int $tg_user_id): void {
		if ($tg_user_id === null) {
			return;
		}
		try {
			$member = $this->api->getChatMember($chat_id, $tg_user_id);
		} catch (Throwable $e) {
			$this->logger->exception($e, 'getChatMember ' . $chat_id);
			return;
		}
		$status = (string) ($member['status'] ?? '');
		if ($status === 'left' || $status === 'kicked' || ($status === 'restricted' && empty($member['is_member']))) {
			throw new PermissionDenied(self::NOT_MEMBER);
		}
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
