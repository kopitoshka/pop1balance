<?php

declare(strict_types=1);

namespace P1Bot\Telegram;

use P1Bot\Repository\AdminRepository;
use P1Bot\Repository\ChatRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Clock;
use P1Bot\Support\Logger;
use Throwable;

/**
	Keeps the admins table in sync with Telegram chat administrators.
	The list is cached and refreshed at most every $ttl_minutes.
*/
class ChatAdminSync {

	public function __construct(
		protected TelegramApi $api,
		protected ChatRepository $chats,
		protected AdminRepository $admins,
		protected PlayerRepository $players,
		protected Clock $clock,
		protected BotIdentity $bot,
		protected Logger $logger,
		protected int $ttl_minutes = 10,
	) {
	}

	public function syncIfStale(int $chat_id): void {
		$synced = $this->chats->adminsSyncedAt($chat_id);
		if ($synced !== null && $synced > $this->clock->now()->modify("-{$this->ttl_minutes} minutes")) {
			return;
		}
		$this->sync($chat_id);
	}

	public function sync(int $chat_id): void {
		try {
			$members = $this->api->getChatAdministrators($chat_id);
		} catch (Throwable $e) {
			// Keep the old cache; try again on the next message.
			$this->logger->exception($e, 'getChatAdministrators');
			return;
		}

		$admins = [];
		foreach ($members as $member) {
			if (!isset($member['user']) || !is_array($member['user'])) {
				continue;
			}
			$user = TgUser::fromArray($member['user']);
			if ($user->is_bot || $user->id === 0) {
				continue;
			}
			$player = $this->players->ensureByTelegram(
				$user->id,
				$user->username,
				$user->displayName(),
				$this->clock->now(),
			);
			$admins[$player->id] = $user->id === $this->bot->owner_tg_id;
		}
		$this->admins->replaceForChat($chat_id, $admins);
		$this->chats->markAdminsSynced($chat_id, $this->clock->now());
	}
}
