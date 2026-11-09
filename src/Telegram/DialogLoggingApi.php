<?php

declare(strict_types=1);

namespace P1Bot\Telegram;

use P1Bot\Support\DialogLog;

/**
	TelegramApi decorator: every reply the bot sends during a user interaction also goes to the
	user's dialog log (see DialogLog). The calls themselves are passed through unchanged.
*/
class DialogLoggingApi implements TelegramApi {

	public function __construct(protected TelegramApi $api, protected DialogLog $dialogs) {
	}

	public function getMe(): array {
		return $this->api->getMe();
	}

	public function sendMessage(
		int $chat_id,
		string $text,
		?int $reply_to_message_id = null,
		array $options = [],
	): int {
		$message_id = $this->api->sendMessage($chat_id, $text, $reply_to_message_id, $options);
		$this->dialogs->onSendMessage($chat_id, $text, $reply_to_message_id);
		return $message_id;
	}

	public function editMessageText(int $chat_id, int $message_id, string $text, array $options = []): void {
		$this->api->editMessageText($chat_id, $message_id, $text, $options);
		$this->dialogs->onEditMessage($chat_id, $text);
	}

	public function answerCallbackQuery(
		string $callback_query_id,
		string $text = '',
		bool $show_alert = false,
		?string $url = null,
	): void {
		$this->api->answerCallbackQuery($callback_query_id, $text, $show_alert, $url);
		$this->dialogs->onCallbackAnswer($callback_query_id, $text);
	}

	public function getChatMember(int $chat_id, int $user_id): array {
		return $this->api->getChatMember($chat_id, $user_id);
	}

	public function getChatAdministrators(int $chat_id): array {
		return $this->api->getChatAdministrators($chat_id);
	}

	public function setWebhook(string $url, string $secret_token, array $allowed_updates): void {
		$this->api->setWebhook($url, $secret_token, $allowed_updates);
	}
}
