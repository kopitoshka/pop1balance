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
 * Keeps the admins table in sync with Telegram chat administrators.
 * The list is cached and refreshed at most every $ttlMinutes.
 */
final class ChatAdminSync
{
    public function __construct(
        private TelegramApi $api,
        private ChatRepository $chats,
        private AdminRepository $admins,
        private PlayerRepository $players,
        private Clock $clock,
        private BotIdentity $bot,
        private Logger $logger,
        private int $ttlMinutes = 10,
    ) {
    }

    public function syncIfStale(int $chatId): void
    {
        $synced = $this->chats->adminsSyncedAt($chatId);
        if ($synced !== null && $synced > $this->clock->now()->modify("-{$this->ttlMinutes} minutes")) {
            return;
        }
        $this->sync($chatId);
    }

    public function sync(int $chatId): void
    {
        try {
            $members = $this->api->getChatAdministrators($chatId);
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
            if ($user->isBot || $user->id === 0) {
                continue;
            }
            $player = $this->players->ensureByTelegram($user->id, $user->username, $user->displayName(), $this->clock->now());
            $admins[$player->id] = $user->id === $this->bot->ownerTgId;
        }
        $this->admins->replaceForChat($chatId, $admins);
        $this->chats->markAdminsSynced($chatId, $this->clock->now());
    }
}
