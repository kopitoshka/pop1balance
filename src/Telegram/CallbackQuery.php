<?php

declare(strict_types=1);

namespace P1Bot\Telegram;

class CallbackQuery {

	public function __construct(
		public string $id,
		public TgUser $from,
		public string $data,
		public ?int $chat_id,
		public ?int $message_id,
		public string $chat_title = '',
	) {
	}

	/**
		@param array<string, mixed> $raw
	*/
	public static function fromArray(array $raw): self {
		$message = $raw['message'] ?? [];
		return new self(
			(string) ($raw['id'] ?? ''),
			TgUser::fromArray($raw['from'] ?? []),
			(string) ($raw['data'] ?? ''),
			isset($message['chat']['id']) ? (int) $message['chat']['id'] : null,
			isset($message['message_id']) ? (int) $message['message_id'] : null,
			(string) ($message['chat']['title'] ?? ''),
		);
	}
}
