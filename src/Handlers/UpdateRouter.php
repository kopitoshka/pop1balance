<?php

declare(strict_types=1);

namespace P1Bot\Handlers;

use P1Bot\Domain\AccessPolicy;
use P1Bot\Domain\Model\Player;
use P1Bot\Repository\ChatRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Clock;
use P1Bot\Telegram\BotIdentity;
use P1Bot\Telegram\ChatAdminSync;
use P1Bot\Telegram\Message;
use P1Bot\Telegram\TelegramApi;
use P1Bot\Telegram\TgUser;
use P1Bot\Telegram\Update;
use P1Bot\View\Texts;

/**
 * Entry point for every update. In groups the bot reacts only to slash commands,
 * @mentions of the bot and replies to its messages. Private chats are ignored for now.
 */
final class UpdateRouter
{
    public function __construct(
        private TelegramApi $api,
        private BotIdentity $bot,
        private ChatRepository $chats,
        private PlayerRepository $players,
        private ChatAdminSync $adminSync,
        private AccessPolicy $access,
        private Clock $clock,
        private AdminCommands $commands,
        private MessageHandler $messages,
        private CallbackHandler $callbacks,
    ) {
    }

    public function handle(Update $update): void
    {
        $membership = $update->botMembershipChange();
        if ($membership !== null) {
            $this->onMembership($membership);
            return;
        }
        if ($update->callbackQuery !== null) {
            $query = $update->callbackQuery;
            if ($query->chatId !== null && !$query->from->isBot) {
                $this->chats->ensure($query->chatId, $query->chatTitle);
                $player = $this->upsertPlayer($query->from);
                $this->callbacks->handle($query, $player);
            }
            return;
        }
        if ($update->message !== null) {
            $this->onMessage($update->message);
        }
    }

    private function onMessage(Message $message): void
    {
        if (!$message->isGroup() || $message->from === null || $message->from->isBot) {
            return;
        }

        $command = $message->command();
        $isOurCommand = $command !== null
            && ($command['bot'] === null || mb_strtolower($command['bot']) === mb_strtolower($this->bot->username))
            && $this->commands->supports($command['name']);
        if (!$isOurCommand && !$message->isAddressedTo($this->bot->username, $this->bot->botId)) {
            return;
        }

        $this->chats->ensure($message->chatId, $message->chatTitle);
        $actor = $this->upsertPlayer($message->from);
        $this->registerMentionedPlayers($message);
        $this->adminSync->syncIfStale($message->chatId);
        $context = new Context($message, $actor, $this->access->isChatAdmin($message->chatId, $actor->id));

        if ($isOurCommand && $command !== null) {
            $this->commands->handle($context, $command['name'], $command['args']);
            return;
        }
        $this->messages->handle($context);
    }

    /** @param array{chat_id: int, title: string, type: string, is_member: bool, was_member: bool} $m */
    private function onMembership(array $m): void
    {
        if ($m['type'] !== 'group' && $m['type'] !== 'supergroup') {
            return;
        }
        if (!$m['is_member']) {
            if ($this->chats->exists($m['chat_id'])) {
                $this->chats->deactivate($m['chat_id']);
            }
            return;
        }
        $this->chats->ensure($m['chat_id'], $m['title']);
        if (!$m['was_member']) {
            $this->api->sendMessage($m['chat_id'], Texts::greeting($this->bot->username));
        }
    }

    private function upsertPlayer(TgUser $user): Player
    {
        return $this->players->ensureByTelegram($user->id, $user->username, $user->displayName(), $this->clock->now());
    }

    /** text_mention users have a real id: create/attach them so later lookups work. */
    private function registerMentionedPlayers(Message $message): void
    {
        foreach ($message->mentionedUsers($this->bot->username) as $mention) {
            if (isset($mention['user']) && !$mention['user']->isBot) {
                $this->upsertPlayer($mention['user']);
            }
        }
    }
}
