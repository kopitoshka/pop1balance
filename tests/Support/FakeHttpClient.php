<?php

declare(strict_types=1);

namespace P1Bot\Tests\Support;

use P1Bot\Http\HttpClient;
use P1Bot\Http\HttpResponse;

/**
	Records requests and answers Telegram / OpenAI calls without network.
	Telegram methods get {"ok":true} with an incrementing message_id unless a handler is set.
*/
class FakeHttpClient implements HttpClient {
	/**
		@var list<array{url: string, method: string, json: array<string, mixed>, headers: array<string, string>}>
	*/
	public array $requests = [];

	/**
		@var array<string, callable(array<string, mixed>): array<string, mixed>> Telegram method => result factory
	*/
	protected array $telegram = [];

	/**
		@var list<array<string, mixed>> queued OpenAI response bodies
	*/
	protected array $open_ai = [];

	protected int $next_message_id = 1000;

	/**
		@param callable(array<string, mixed>): array<string, mixed> $handler returns the full Bot API response
	*/
	public function onTelegram(string $method, callable $handler): void {
		$this->telegram[$method] = $handler;
	}

	/**
		@param array<string, mixed> $body
	*/
	public function queueOpenAi(array $body): void {
		$this->open_ai[] = $body;
	}

	public function postJson(string $url, array $json, array $headers = [], int $timeout = 15): HttpResponse {
		$method = (string) substr($url, (int) strrpos($url, '/') + 1);
		$this->requests[] = ['url' => $url, 'method' => $method, 'json' => $json, 'headers' => $headers];

		if (str_contains($url, 'api.openai.com') || str_contains($url, '/responses')) {
			$body = array_shift($this->open_ai);
			if ($body === null) {
				return new HttpResponse(500, '{"error":{"message":"no queued response"}}');
			}
			return new HttpResponse(200, (string) json_encode($body, JSON_UNESCAPED_UNICODE));
		}

		if (isset($this->telegram[$method])) {
			return new HttpResponse(
				200,
				(string) json_encode(($this->telegram[$method])($json), JSON_UNESCAPED_UNICODE),
			);
		}
		$result = in_array($method, ['sendMessage', 'editMessageText'], true)
			? ['message_id' => $method === 'sendMessage' ? $this->next_message_id++ : ($json['message_id'] ?? 0)]
			: true;
		if ($method === 'getChatAdministrators') {
			$result = [];
		}
		if ($method === 'getChat') {
			$result = [
				'id' => (int) ($json['chat_id'] ?? 0),
				'type' => 'supergroup',
				'title' => 'Test chat',
			];
		}
		if ($method === 'getChatMember') {
			// Default: user is a member (tests that need left/kicked set their own handler).
			$result = [
				'status' => 'member',
				'user' => ['id' => (int) ($json['user_id'] ?? 0), 'is_bot' => false, 'first_name' => 'T'],
			];
		}
		return new HttpResponse(200, (string) json_encode(['ok' => true, 'result' => $result]));
	}

	/**
		@return list<array<string, mixed>> request bodies for a Telegram method
	*/
	public function sent(string $method): array {
		$result = [];
		foreach ($this->requests as $request) {
			if ($request['method'] === $method) {
				$result[] = $request['json'];
			}
		}
		return $result;
	}

	public function lastText(): string {
		$all = array_merge($this->sent('sendMessage'));
		$last = end($all);
		return $last === false ? '' : (string) $last['text'];
	}

	public function reset(): void {
		$this->requests = [];
	}
}
