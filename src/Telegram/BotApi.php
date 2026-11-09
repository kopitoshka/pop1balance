<?php

declare(strict_types=1);

namespace P1Bot\Telegram;

use P1Bot\Http\HttpClient;
use P1Bot\Support\Logger;
use P1Bot\Support\Redact;
use Throwable;

/**
	Bot API over HTTPS (JSON bodies). Messages are sent with parse_mode=HTML and no link previews.
*/
class BotApi implements TelegramApi {
	protected const BASE = 'https://api.telegram.org/bot';

	public function __construct(
		protected HttpClient $http,
		protected string $token,
		protected int $timeout = 15,
		protected ?Logger $logger = null,
	) {
	}

	/**
		Bot user id is the numeric part of the token before ':'.
	*/
	public static function botIdFromToken(string $token): int {
		$id = strstr($token, ':', true);
		return $id !== false && ctype_digit($id) ? (int) $id : 0;
	}

	public function getMe(): array {
		return $this->call('getMe', []);
	}

	public function sendMessage(
		int $chat_id,
		string $text,
		?int $reply_to_message_id = null,
		array $options = [],
	): int {
		$params = [
			'chat_id' => $chat_id,
			'text' => $text,
			'parse_mode' => 'HTML',
			'link_preview_options' => ['is_disabled' => true],
		];
		if ($reply_to_message_id !== null) {
			$params['reply_parameters'] = ['message_id' => $reply_to_message_id, 'allow_sending_without_reply' => true];
		}
		$result = $this->call('sendMessage', $options + $params);
		return (int) ($result['message_id'] ?? 0);
	}

	public function editMessageText(int $chat_id, int $message_id, string $text, array $options = []): void {
		$this->call('editMessageText', $options + [
			'chat_id' => $chat_id,
			'message_id' => $message_id,
			'text' => $text,
			'parse_mode' => 'HTML',
			'link_preview_options' => ['is_disabled' => true],
		]);
	}

	public function answerCallbackQuery(
		string $callback_query_id,
		string $text = '',
		bool $show_alert = false,
		?string $url = null,
	): void {
		$params = ['callback_query_id' => $callback_query_id];
		if ($text !== '') {
			$params['text'] = $text;
			$params['show_alert'] = $show_alert;
		}
		if ($url !== null) {
			$params['url'] = $url;
		}
		$this->call('answerCallbackQuery', $params);
	}

	/**
		@return array<string, mixed> Chat object
	*/
	public function getChat(int $chat_id): array {
		return $this->call('getChat', ['chat_id' => $chat_id]);
	}

	public function getChatMember(int $chat_id, int $user_id): array {
		return $this->call('getChatMember', ['chat_id' => $chat_id, 'user_id' => $user_id]);
	}

	public function getChatAdministrators(int $chat_id): array {
		return array_values($this->call('getChatAdministrators', ['chat_id' => $chat_id]));
	}

	/**
		@return array<string, mixed>
	*/
	public function getWebhookInfo(): array {
		return $this->call('getWebhookInfo', []);
	}

	public function setWebhook(string $url, string $secret_token, array $allowed_updates): void {
		$this->call('setWebhook', [
			'url' => $url,
			'secret_token' => $secret_token,
			'allowed_updates' => $allowed_updates,
			'drop_pending_updates' => false,
		]);
	}

	public function answerInlineQuery(string $inline_query_id, array $results, array $options = []): void {
		$this->call('answerInlineQuery', $options + [
			'inline_query_id' => $inline_query_id,
			'results' => array_values($results),
		]);
	}

	/**
		@param list<array{command: string, description: string}> $commands
		@param array<string, mixed>|null $scope BotCommandScope, e.g. ['type' => 'all_private_chats']
	*/
	public function setMyCommands(array $commands, ?array $scope = null): void {
		$params = ['commands' => $commands];
		if ($scope !== null) {
			$params['scope'] = $scope;
		}
		$this->call('setMyCommands', $params);
	}

	/**
		@param array<string, mixed> $params
		@return array<string, mixed>
	*/
	protected function call(string $method, array $params): array {
		try {
			$response = $this->http->postJson(self::BASE . $this->token . '/' . $method, $params, [], $this->timeout);
		} catch (Throwable $e) {
			// The URL contains the token: never log it, and scrub it from the message just in case.
			$this->logger?->error(
				sprintf('telegram %s failed: %s', $method, Redact::text($e->getMessage(), [$this->token])),
				$this->target($params),
			);
			throw $e;
		}
		$data = $response->json();
		if (($data['ok'] ?? false) !== true) {
			$description = (string) ($data['description'] ?? 'unknown error');
			$code = (int) ($data['error_code'] ?? $response->status);
			$line = sprintf('telegram %s ok=false: %d %s', $method, $code, Redact::text($description, [$this->token]));
			if (stripos($description, 'message is not modified') !== false) {
				$this->logger?->debug($line, $this->target($params));
			} else {
				$this->logger?->error($line, $this->target($params));
			}
			$parameters = isset($data['parameters']) && is_array($data['parameters']) ? $data['parameters'] : [];
			throw new TelegramError($method, $description, $code, $parameters);
		}
		$result = $data['result'] ?? [];
		return is_array($result) ? $result : ['value' => $result];
	}

	/**
		@param array<string, mixed> $params
		@return array<string, mixed>
	*/
	protected function target(array $params): array {
		$target = [];
		foreach (['chat_id', 'message_id'] as $key) {
			if (isset($params[$key])) {
				$target[$key] = $params[$key];
			}
		}
		if (isset($params['text']) && is_string($params['text'])) {
			$target['text'] = mb_substr($params['text'], 0, 100);
		}
		return $target;
	}
}
