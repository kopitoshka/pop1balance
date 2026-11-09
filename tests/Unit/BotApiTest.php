<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use P1Bot\Telegram\BotApi;
use P1Bot\Telegram\TelegramError;
use P1Bot\Telegram\Update;
use P1Bot\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

class BotApiTest extends TestCase {

	public function testSendMessageAsReply(): void {
		$http = new FakeHttpClient();
		$api = new BotApi($http, '123:ABC');

		$id = $api->sendMessage(-100, '<b>Привет</b>', 77, ['reply_markup' => ['inline_keyboard' => []]]);

		self::assertSame(1000, $id);
		$request = $http->requests[0];
		self::assertSame('https://api.telegram.org/bot123:ABC/sendMessage', $request['url']);
		self::assertSame('HTML', $request['json']['parse_mode']);
		self::assertSame(
			['message_id' => 77, 'allow_sending_without_reply' => true],
			$request['json']['reply_parameters'],
		);
		self::assertSame(['is_disabled' => true], $request['json']['link_preview_options']);
		self::assertArrayHasKey('reply_markup', $request['json']);
	}

	public function testSetWebhook(): void {
		$http = new FakeHttpClient();
		(new BotApi($http, '123:ABC'))->setWebhook('https://x.test/webhook.php', 'sec', Update::ALLOWED_UPDATES);
		$json = $http->sent('setWebhook')[0];
		self::assertSame('sec', $json['secret_token']);
		self::assertSame(Update::ALLOWED_UPDATES, $json['allowed_updates']);
	}

	public function testErrorIsRaised(): void {
		$http = new FakeHttpClient();
		$http->onTelegram('editMessageText', static fn (): array => [
			'ok' => false,
			'error_code' => 400,
			'description' => 'Bad Request: message is not modified',
		]);
		try {
			(new BotApi($http, '1:x'))->editMessageText(-1, 2, 'same');
			self::fail('Expected TelegramError');
		} catch (TelegramError $e) {
			self::assertTrue($e->isNotModified());
			self::assertSame(400, $e->getCode());
		}
	}

	public function testBotIdFromToken(): void {
		self::assertSame(123456, BotApi::botIdFromToken('123456:AAA'));
		self::assertSame(0, BotApi::botIdFromToken('garbage'));
	}
}
