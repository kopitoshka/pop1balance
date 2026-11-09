<?php

declare(strict_types=1);

namespace P1Bot\Telegram;

/** Parsed Telegram update: a message, a callback query or a change of the bot's own membership. */
final class Update
{
    /** Update types requested in setWebhook. */
    public const ALLOWED_UPDATES = ['message', 'callback_query', 'my_chat_member'];

    /**
     * @param array<string, mixed>|null $myChatMember raw ChatMemberUpdated
     */
    private function __construct(
        public int $updateId,
        public ?Message $message,
        public ?CallbackQuery $callbackQuery,
        public ?array $myChatMember,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            (int) ($raw['update_id'] ?? 0),
            isset($raw['message']) && is_array($raw['message']) ? Message::fromArray($raw['message']) : null,
            isset($raw['callback_query']) && is_array($raw['callback_query']) ? CallbackQuery::fromArray($raw['callback_query']) : null,
            isset($raw['my_chat_member']) && is_array($raw['my_chat_member']) ? $raw['my_chat_member'] : null,
        );
    }

    /**
     * Change of the bot's own membership.
     *
     * @return array{chat_id: int, title: string, type: string, is_member: bool, was_member: bool}|null
     */
    public function botMembershipChange(): ?array
    {
        if ($this->myChatMember === null) {
            return null;
        }
        $status = (string) ($this->myChatMember['new_chat_member']['status'] ?? '');
        $old = (string) ($this->myChatMember['old_chat_member']['status'] ?? '');
        $member = ['member', 'administrator', 'creator'];
        return [
            'chat_id' => (int) ($this->myChatMember['chat']['id'] ?? 0),
            'title' => (string) ($this->myChatMember['chat']['title'] ?? ''),
            'type' => (string) ($this->myChatMember['chat']['type'] ?? ''),
            'is_member' => in_array($status, $member, true),
            'was_member' => in_array($old, $member, true),
        ];
    }
}
