<?php

declare(strict_types=1);

namespace P1Bot\Telegram;

/** Incoming message. Entity offsets are in UTF-16 code units, as in the Bot API. */
final class Message
{
    /**
     * @param list<array<string, mixed>> $entities
     */
    public function __construct(
        public int $messageId,
        public int $chatId,
        public string $chatType,
        public string $chatTitle,
        public ?TgUser $from,
        public string $text,
        public array $entities,
        public ?int $replyToMessageId,
        public ?TgUser $replyToFrom,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $chat = $raw['chat'] ?? [];
        $reply = $raw['reply_to_message'] ?? null;
        $text = (string) ($raw['text'] ?? $raw['caption'] ?? '');
        $entities = $raw['entities'] ?? $raw['caption_entities'] ?? [];

        return new self(
            (int) ($raw['message_id'] ?? 0),
            (int) ($chat['id'] ?? 0),
            (string) ($chat['type'] ?? ''),
            (string) ($chat['title'] ?? ''),
            isset($raw['from']) ? TgUser::fromArray($raw['from']) : null,
            $text,
            is_array($entities) ? array_values($entities) : [],
            is_array($reply) ? (int) ($reply['message_id'] ?? 0) : null,
            is_array($reply) && isset($reply['from']) ? TgUser::fromArray($reply['from']) : null,
        );
    }

    public function isGroup(): bool
    {
        return $this->chatType === 'group' || $this->chatType === 'supergroup';
    }

    /** Text of an entity, honouring UTF-16 offsets. */
    public function entityText(array $entity): string
    {
        $utf16 = mb_convert_encoding($this->text, 'UTF-16LE', 'UTF-8');
        $part = substr($utf16, (int) $entity['offset'] * 2, (int) $entity['length'] * 2);
        return mb_convert_encoding($part, 'UTF-8', 'UTF-16LE');
    }

    /**
     * Slash command at the start of the message: ['name' => 'new', 'bot' => 'mybot'|null, 'args' => '...'].
     *
     * @return array{name: string, bot: ?string, args: string}|null
     */
    public function command(): ?array
    {
        foreach ($this->entities as $entity) {
            if (($entity['type'] ?? '') === 'bot_command' && (int) $entity['offset'] === 0) {
                $raw = ltrim($this->entityText($entity), '/');
                $bot = null;
                if (str_contains($raw, '@')) {
                    [$raw, $bot] = explode('@', $raw, 2);
                }
                $utf16 = mb_convert_encoding($this->text, 'UTF-16LE', 'UTF-8');
                $rest = mb_convert_encoding(substr($utf16, (int) $entity['length'] * 2), 'UTF-8', 'UTF-16LE');
                return ['name' => mb_strtolower($raw), 'bot' => $bot, 'args' => trim($rest)];
            }
        }
        return null;
    }

    public function mentions(string $botUsername): bool
    {
        $needle = '@' . mb_strtolower(ltrim($botUsername, '@'));
        foreach ($this->entities as $entity) {
            if (($entity['type'] ?? '') === 'mention' && mb_strtolower($this->entityText($entity)) === $needle) {
                return true;
            }
        }
        return false;
    }

    public function isReplyToUser(int $userId): bool
    {
        return $this->replyToFrom !== null && $this->replyToFrom->id === $userId;
    }

    /**
     * Group etiquette (privacy mode): react only to @mentions of the bot or replies to the bot.
     */
    public function isAddressedTo(string $botUsername, int $botId): bool
    {
        return $this->isGroup() && ($this->mentions($botUsername) || $this->isReplyToUser($botId));
    }

    /** Text without "@botname" mentions, for the AI. */
    public function textWithoutMention(string $botUsername): string
    {
        $text = preg_replace('/@' . preg_quote(ltrim($botUsername, '@'), '/') . '\b/iu', '', $this->text) ?? $this->text;
        return trim(preg_replace('/\s{2,}/u', ' ', $text) ?? $text);
    }

    /**
     * Users mentioned in the message, except the bot: ['username' => 'vasya'] for @mentions,
     * ['user' => TgUser] for text_mention (users without username); 'text' is the entity text.
     *
     * @return list<array{text: string, username?: string, user?: TgUser}>
     */
    public function mentionedUsers(string $botUsername): array
    {
        $bot = mb_strtolower(ltrim($botUsername, '@'));
        $result = [];
        foreach ($this->entities as $entity) {
            $type = $entity['type'] ?? '';
            if ($type === 'mention') {
                $text = $this->entityText($entity);
                $username = ltrim($text, '@');
                if (mb_strtolower($username) !== $bot) {
                    $result[] = ['text' => $text, 'username' => $username];
                }
            } elseif ($type === 'text_mention' && isset($entity['user'])) {
                $result[] = ['text' => $this->entityText($entity), 'user' => TgUser::fromArray($entity['user'])];
            }
        }
        return $result;
    }
}
