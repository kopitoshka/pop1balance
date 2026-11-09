<?php

declare(strict_types=1);

namespace P1Bot\Telegram;

/**
	Who the bot is and who owns it.
*/
class BotIdentity {

	public function __construct(
		public int $bot_id,
		public string $username,
		public int $owner_tg_id,
	) {
	}
}
