<?php

declare(strict_types=1);

namespace P1Bot\Handlers;

use P1Bot\Domain\AccessPolicy;
use P1Bot\Domain\Model\Player;
use P1Bot\Repository\ChatRepository;
use P1Bot\Repository\EditedReplyRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Clock;
use P1Bot\Support\Logger;
use P1Bot\Telegram\BotIdentity;
use P1Bot\Telegram\ChatAdminSync;
use P1Bot\Telegram\Message;
use P1Bot\Telegram\TelegramApi;
use P1Bot\Telegram\TgUser;
use P1Bot\Telegram\Update;
use P1Bot\View\Html;
use P1Bot\View\Messages;
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
        private ?EditedReplyRepository $editedReplies = null,
        private ?Logger $logger = null,
    ) {
    }

    public function handle(Update $update): void
    {
        $membership = $update->botMembershipChange();
        if ($membership !== null) {
            $this->log($update, null, sprintf('membership: chat %d (%s) %s', $membership['chat_id'], $membership['type'],
                $membership['is_member'] ? ($membership['was_member'] ? 'still member' : 'bot added') : 'bot removed'));
            $this->onMembership($membership);
            return;
        }
        if ($update->callbackQuery !== null) {
            $query = $update->callbackQuery;
            if ($query->chatId === null || $query->from->isBot) {
                $this->log($update, null, 'ignored: callback without chat or from a bot');
                return;
            }
            $this->log($update, null, 'handled: callback ' . $query->data, [
                'chat' => $query->chatId, 'from' => $query->from->id, 'username' => $query->from->username,
            ]);
            $this->chats->ensure($query->chatId, $query->chatTitle);
            $player = $this->upsertPlayer($query->from);
            $this->callbacks->handle($query, $player);
            return;
        }
        if ($update->message !== null) {
            $this->onMessage($update, $update->message);
            return;
        }
        if ($update->editedMessage !== null) {
            $this->onEditedMessage($update, $update->editedMessage);
            return;
        }
        $this->log($update, null, 'ignored: unsupported update type');
    }

    /**
     * Edits are never processed (no commands, no AI). If the edit mentions the bot or replies to it,
     * say once per message that a new message is needed.
     */
    private function onEditedMessage(Update $update, Message $message): void
    {
        if (!$message->isGroup()) {
            $this->log($update, $message, 'ignored: edited, not a group (' . $message->chatType . ')');
            return;
        }
        if ($message->from === null || $message->from->isBot) {
            $this->log($update, $message, 'ignored: edited, from a bot or no sender');
            return;
        }
        if (!$message->isAddressedTo($this->bot->username, $this->bot->botId)) {
            $this->log($update, $message, 'ignored: edited, ' . $this->whyNotAddressed($message));
            return;
        }
        if ($this->editedReplies === null) {
            $this->log($update, $message, 'ignored: edited, dedupe storage unavailable');
            return;
        }
        if (!$this->editedReplies->claim($message->chatId, $message->messageId, $this->clock->now())) {
            $this->log($update, $message, 'ignored: edited, already replied to this message');
            return;
        }
        $this->log($update, $message, 'edited: replied once');
        $this->api->sendMessage($message->chatId, Html::e(Messages::EDITED_MESSAGE), $message->messageId);
    }

    private function onMessage(Update $update, Message $message): void
    {
        if (!$message->isGroup()) {
            $this->log($update, $message, $message->chatType === 'private' ? 'ignored: private chat' : 'ignored: not a group (' . $message->chatType . ')');
            return;
        }
        if ($message->from === null || $message->from->isBot) {
            $this->log($update, $message, 'ignored: from a bot or no sender');
            return;
        }

        $command = $message->command();
        $isOurCommand = $command !== null
            && ($command['bot'] === null || mb_strtolower($command['bot']) === mb_strtolower($this->bot->username))
            && $this->commands->supports($command['name']);
        if (!$isOurCommand && !$message->isAddressedTo($this->bot->username, $this->bot->botId)) {
            if ($command !== null && $command['bot'] !== null && mb_strtolower($command['bot']) !== mb_strtolower($this->bot->username)) {
                $reason = sprintf('ignored: command /%s@%s is for another bot', $command['name'], $command['bot']);
            } elseif ($command !== null) {
                $reason = sprintf('ignored: unknown command /%s', $command['name']);
            } else {
                $reason = 'ignored: ' . $this->whyNotAddressed($message);
            }
            $this->log($update, $message, $reason);
            return;
        }

        $this->log($update, $message, $isOurCommand && $command !== null
            ? 'handled: command /' . $command['name']
            : 'handled: ' . $this->messages->mode());

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

    /** Why a group message is not for us; names the username we compared against. */
    private function whyNotAddressed(Message $message): string
    {
        $mentions = $message->mentionedUsernames();
        $ours = '@' . mb_strtolower($this->bot->username);
        if ($this->bot->username === '') {
            return 'no mention/reply (TELEGRAM_BOT_USERNAME is empty!)';
        }
        $botLike = array_values(array_filter($mentions, static function (string $m): bool {
            return (bool) preg_match('/bot$/u', $m);
        }));
        if ($botLike !== []) {
            return sprintf('wrong bot username in mention (%s, expected %s from TELEGRAM_BOT_USERNAME)', implode(', ', $botLike), $ours);
        }
        if ($message->replyToFrom !== null && $message->replyToFrom->isBot && $message->replyToFrom->id !== $this->bot->botId) {
            return sprintf('reply to another bot (id %d, ours is %d)', $message->replyToFrom->id, $this->bot->botId);
        }
        return sprintf('no mention/reply (expected %s)', $ours);
    }

    /**
     * One info line per update: id, type, chat, sender, text start and the decision.
     *
     * @param array<string, mixed> $extra
     */
    private function log(Update $update, ?Message $message, string $decision, array $extra = []): void
    {
        if ($this->logger === null) {
            return;
        }
        $context = ['type' => $update->type];
        if ($message !== null) {
            $text = preg_replace('/\s+/u', ' ', $message->text) ?? $message->text;
            $context += [
                'chat' => $message->chatId,
                'chat_type' => $message->chatType,
                'from' => $message->from !== null ? $message->from->id : null,
                'username' => $message->from !== null && $message->from->username !== null ? '@' . $message->from->username : null,
                'text' => mb_strlen($text) > 100 ? mb_substr($text, 0, 100) . '…' : $text,
                'bot' => '@' . $this->bot->username,
            ];
        }
        $this->logger->info(sprintf('update %d %s: %s', $update->updateId, $update->type, $decision), $context + $extra);
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
