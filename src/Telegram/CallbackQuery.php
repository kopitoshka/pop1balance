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
		public string $button_text = '',
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
			self::buttonText($message['reply_markup']['inline_keyboard'] ?? [], (string) ($raw['data'] ?? '')),
		);
	}

	/**
		Label of the pressed button, found by its callback_data in the message's keyboard ('' if unknown).

		@param mixed $keyboard
	*/
	protected static function buttonText($keyboard, string $data): string {
		foreach (is_array($keyboard) ? $keyboard : [] as $row) {
			foreach (is_array($row) ? $row : [] as $button) {
				if (is_array($button) && ($button['callback_data'] ?? null) === $data) {
					return (string) ($button['text'] ?? '');
				}
			}
		}
		return '';
	}
}
