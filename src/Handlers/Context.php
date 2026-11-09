<?php

declare(strict_types=1);

namespace P1Bot\Handlers;

use P1Bot\Domain\Model\Player;
use P1Bot\Telegram\Message;

/**
	A message addressed to the bot (in a group or in private), with the resolved author.
*/
class Context {

	/**
		@param int|null $group_id the group whose tournaments this message is about; in a group it is
			the group itself, in a private chat the group chosen for the player (null = not chosen)
		@param string $group_title title of that group (for the AI context in private)
	*/
	public function __construct(
		public Message $message,
		public Player $actor,
		public bool $is_admin,
		public bool $is_owner = false,
		public ?int $group_id = null,
		public string $group_title = '',
	) {
	}

	/**
		Where to answer: the chat the message came from.
	*/
	public function chatId(): int {
		return $this->message->chat_id;
	}

	/**
		Which group's tournaments: the group itself, or in private the chosen group.
	*/
	public function groupId(): int {
		return $this->group_id ?? $this->message->chat_id;
	}

	public function isPrivate(): bool {
		return $this->message->chat_type === 'private';
	}
}
