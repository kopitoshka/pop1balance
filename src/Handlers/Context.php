<?php

declare(strict_types=1);

namespace P1Bot\Handlers;

use P1Bot\Domain\Model\Player;
use P1Bot\Telegram\Message;

/**
	A group message addressed to the bot, with the resolved author.
*/
class Context {

	public function __construct(
		public Message $message,
		public Player $actor,
		public bool $is_admin,
		public bool $is_owner = false,
	) {
	}

	public function chatId(): int {
		return $this->message->chat_id;
	}
}
