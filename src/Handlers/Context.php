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
		@param bool $is_super the bot-owner abilities are on (AccessPolicy::isSuper)
		@param bool $can_su the player has the /su right (shown in /help)
		@param bool $can_manage_su the player may /su grant, revoke and list
	*/
	public function __construct(
		public Message $message,
		public Player $actor,
		public bool $is_admin,
		public bool $is_super = false,
		public ?int $group_id = null,
		public string $group_title = '',
		public bool $can_su = false,
		public bool $can_manage_su = false,
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
