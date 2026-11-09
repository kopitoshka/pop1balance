<?php

declare(strict_types=1);

namespace P1Bot\Telegram;

/**
	Telegram Bot API surface the bot needs.
*/
interface TelegramApi {

	/**
		@return array<string, mixed> bot User object
	*/
	public function getMe(): array;

	/**
		Sends an HTML message. When $reply_to_message_id is set the message is a reply
		(sent even if the original was deleted).

		@param array<string, mixed> $options extra Bot API fields, e.g. reply_markup
		@return int message_id of the sent message
	*/
	public function sendMessage(int $chat_id, string $text, ?int $reply_to_message_id = null, array $options = []): int;

	/**
		@param array<string, mixed> $options
	*/
	public function editMessageText(int $chat_id, int $message_id, string $text, array $options = []): void;

	/**
		$url may be a t.me/<bot>?start=... link: the client opens the private chat with the bot.
	*/
	public function answerCallbackQuery(
		string $callback_query_id,
		string $text = '',
		bool $show_alert = false,
		?string $url = null,
	): void;

	/**
		@return array<string, mixed> ChatMember object (status: creator, administrator, member, restricted, left, kicked)
	*/
	public function getChatMember(int $chat_id, int $user_id): array;

	/**
		@return list<array<string, mixed>> ChatMember objects
	*/
	public function getChatAdministrators(int $chat_id): array;

	/**
		@param list<string> $allowed_updates
	*/
	public function setWebhook(string $url, string $secret_token, array $allowed_updates): void;
}
