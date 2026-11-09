<?php

declare(strict_types=1);

namespace P1Bot\Telegram;

/**
	Incoming message. Entity offsets are in UTF-16 code units, as in the Bot API.
*/
class Message {

	/**
		@param list<array<string, mixed>> $entities
		@param array{request_id: int, users: list<array{id: int, username: ?string, first_name: string, last_name: string}>}|null $users_shared
	*/
	public function __construct(
		public int $message_id,
		public int $chat_id,
		public string $chat_type,
		public string $chat_title,
		public ?TgUser $from,
		public string $text,
		public array $entities,
		public ?int $reply_to_message_id,
		public ?TgUser $reply_to_from,
		public ?array $users_shared = null,
	) {
	}

	/**
		@param array<string, mixed> $raw
	*/
	public static function fromArray(array $raw): self {
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
			self::parseUsersShared($raw['users_shared'] ?? null),
		);
	}

	/**
		@param mixed $raw
		@return array{request_id: int, users: list<array{id: int, username: ?string, first_name: string, last_name: string}>}|null
	*/
	protected static function parseUsersShared(mixed $raw): ?array {
		if (!is_array($raw)) {
			return null;
		}
		$users = [];
		if (isset($raw['users']) && is_array($raw['users'])) {
			foreach ($raw['users'] as $u) {
				if (!is_array($u)) {
					continue;
				}
				$id = (int) ($u['user_id'] ?? $u['id'] ?? 0);
				if ($id <= 0) {
					continue;
				}
				$username = isset($u['username']) && $u['username'] !== '' ? (string) $u['username'] : null;
				$users[] = [
					'id' => $id,
					'username' => $username,
					'first_name' => (string) ($u['first_name'] ?? ''),
					'last_name' => (string) ($u['last_name'] ?? ''),
				];
			}
		} elseif (isset($raw['user_ids']) && is_array($raw['user_ids'])) {
			foreach ($raw['user_ids'] as $id) {
				$id = (int) $id;
				if ($id <= 0) {
					continue;
				}
				$users[] = [
					'id' => $id,
					'username' => null,
					'first_name' => '',
					'last_name' => '',
				];
			}
		}
		if ($users === []) {
			return null;
		}
		return [
			'request_id' => (int) ($raw['request_id'] ?? 0),
			'users' => $users,
		];
	}

	public function isGroup(): bool {
		return $this->chat_type === 'group' || $this->chat_type === 'supergroup';
	}

	/**
		Text of an entity, honouring UTF-16 offsets.
	*/
	public function entityText(array $entity): string {
		$utf16 = mb_convert_encoding($this->text, 'UTF-16LE', 'UTF-8');
		$part = substr($utf16, (int) $entity['offset'] * 2, (int) $entity['length'] * 2);
		return mb_convert_encoding($part, 'UTF-8', 'UTF-16LE');
	}

	/**
		Slash command at the start of the message: ['name' => 'new', 'bot' => 'mybot'|null, 'args' => '...'].

		@return array{name: string, bot: ?string, args: string}|null
	*/
	public function command(): ?array {
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

	public function mentions(string $bot_username): bool {
		$needle = '@' . mb_strtolower(ltrim($bot_username, '@'));
		foreach ($this->entities as $entity) {
			if (($entity['type'] ?? '') === 'mention' && mb_strtolower($this->entityText($entity)) === $needle) {
				return true;
			}
		}
		return false;
	}

	/**
		@return list<string> usernames of all @mentions in the message, lowercase, with "@"
	*/
	public function mentionedUsernames(): array {
		$names = [];
		foreach ($this->entities as $entity) {
			if (($entity['type'] ?? '') === 'mention') {
				$names[] = mb_strtolower($this->entityText($entity));
			}
		}
		return $names;
	}

	public function isReplyToUser(int $user_id): bool {
		return $this->reply_to_from !== null && $this->reply_to_from->id === $user_id;
	}

	/**
		Group etiquette (privacy mode): react only to @mentions of the bot or replies to the bot.
	*/
	public function isAddressedTo(string $bot_username, int $bot_id): bool {
		return $this->isGroup() && ($this->mentions($bot_username) || $this->isReplyToUser($bot_id));
	}

	/**
		Text without "@botname" mentions, for the AI.
	*/
	public function textWithoutMention(string $bot_username): string {
		$text = preg_replace(
			'/@' . preg_quote(ltrim($bot_username, '@'), '/') . '\b/iu',
			'',
			$this->text,
		) ?? $this->text;
		return trim(preg_replace('/\s{2,}/u', ' ', $text) ?? $text);
	}

	/**
		Users mentioned in the message, except the bot: ['username' => 'vasya'] for @mentions,
		['user' => TgUser] for text_mention (users without username); 'text' is the entity text.

		@return list<array{text: string, username?: string, user?: TgUser}>
	*/
	public function mentionedUsers(string $bot_username): array {
		$bot = mb_strtolower(ltrim($bot_username, '@'));
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
