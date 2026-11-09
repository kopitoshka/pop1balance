<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use P1Bot\Telegram\BotApi;
use P1Bot\Telegram\TelegramError;
use P1Bot\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

class TelegramErrorRetryTest extends TestCase {

	public function testRetryAfterFromParameters(): void {
		$e = new TelegramError('getChatMember', 'Too Many Requests: retry after 3', 429, [
			'retry_after' => 3,
		]);
		self::assertSame(3, $e->retryAfter());
		self::assertNull((new TelegramError('x', 'y', 400))->retryAfter());
	}

	public function testBotApiPassesParameters(): void {
		$http = new FakeHttpClient();
		$http->onTelegram('getChatMember', static fn (): array => [
			'ok' => false,
			'error_code' => 429,
			'description' => 'Too Many Requests: retry after 2',
			'parameters' => ['retry_after' => 2],
		]);
		try {
			(new BotApi($http, '1:x'))->getChatMember(-100, 5);
			self::fail('expected TelegramError');
		} catch (TelegramError $e) {
			self::assertSame(429, $e->getCode());
			self::assertSame(2, $e->retryAfter());
		}
	}

	public function testGetChatReturnsChat(): void {
		$http = new FakeHttpClient();
		$http->onTelegram('getChat', static fn (): array => [
			'ok' => true,
			'result' => ['id' => -1001, 'type' => 'supergroup', 'title' => 'Demo'],
		]);
		$chat = (new BotApi($http, '1:x'))->getChat(-1001);
		self::assertSame('Demo', $chat['title']);
		self::assertSame(-1001, $chat['id']);
	}
}
