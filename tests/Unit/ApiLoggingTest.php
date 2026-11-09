<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use P1Bot\Ai\AiError;
use P1Bot\Ai\OpenAiClient;
use P1Bot\Http\HttpClient;
use P1Bot\Http\HttpError;
use P1Bot\Http\HttpResponse;
use P1Bot\Telegram\BotApi;
use P1Bot\Telegram\TelegramError;
use P1Bot\Tests\Support\FakeHttpClient;
use P1Bot\Tests\Support\LogCapture;
use PHPUnit\Framework\TestCase;

class ApiLoggingTest extends TestCase {
	protected const TOKEN = '123456:SECRETTOKENabcdefghijklmnop';

	public function testTelegramOkFalseIsLoggedAsErrorWithoutToken(): void {
		$log = new LogCapture();
		$http = new FakeHttpClient();
		$http->onTelegram(
			'sendMessage',
			static fn (): array => ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'],
		);
		$api = new BotApi($http, self::TOKEN, 15, $log->logger);
		try {
			$api->sendMessage(-100, 'привет');
			self::fail('expected TelegramError');
		} catch (TelegramError $e) {
		}
		$text = $log->text();
		self::assertStringContainsString('ERROR telegram sendMessage ok=false: 400 Bad Request: chat not found', $text);
		self::assertStringContainsString('"chat_id":-100', $text);
		self::assertStringNotContainsString('SECRETTOKEN', $text);
	}

	public function testNotModifiedIsNotAnError(): void {
		$log = new LogCapture();
		$http = new FakeHttpClient();
		$http->onTelegram(
			'editMessageText',
			static fn (): array => [
				'ok' => false,
				'error_code' => 400,
				'description' => 'Bad Request: message is not modified',
			],
		);
		try {
			(new BotApi($http, self::TOKEN, 15, $log->logger))->editMessageText(-1, 2, 'same');
		} catch (TelegramError $e) {
		}
		self::assertStringNotContainsString('ERROR', $log->text());
	}

	public function testTransportFailureIsLoggedAndTokenScrubbed(): void {
		$log = new LogCapture();
		$http = new class implements HttpClient {

			public function postJson(string $url, array $json, array $headers = [], int $timeout = 15): HttpResponse {
				throw new HttpError('HTTP request failed: timeout on ' . $url);
			}
		};
		try {
			(new BotApi($http, self::TOKEN, 15, $log->logger))->getMe();
		} catch (HttpError $e) {
		}
		$text = $log->text();
		self::assertStringContainsString('ERROR telegram getMe failed', $text);
		self::assertStringNotContainsString('SECRETTOKEN', $text);
	}

	public function testOpenAiSuccessAndFailureAreLogged(): void {
		$log = new LogCapture();
		$http = new FakeHttpClient();
		$http->queueOpenAi(['id' => 'r1', 'output' => []]);
		$client = new OpenAiClient(
			$http,
			'sk-live-KEY1234567890',
			'gpt-test',
			'low',
			'https://api.openai.com/v1',
			30,
			$log->logger,
		);

		$client->createResponse('sys', [], []);
		self::assertMatchesRegularExpression(
			'/INFO ai request start: model=gpt-test effort=low new conversation/',
			$log->text(),
		);
		self::assertMatchesRegularExpression('/INFO ai response HTTP 200 after \d+ms, model=gpt-test/', $log->text());

		try {
			$client->createResponse('sys', [], [], 'r1'); // queue empty: fake answers 500
			self::fail('expected AiError');
		} catch (AiError $e) {
		}
		$text = $log->text();
		self::assertStringContainsString('continues r1', $text);
		self::assertMatchesRegularExpression(
			'/ERROR ai response HTTP 500 after \d+ms, model=gpt-test: \{"error":\{"message":"no queued response"\}\}/',
			$text,
		);
		self::assertStringNotContainsString('KEY1234567890', $text);
	}

	public function testGetWebhookInfo(): void {
		$http = new FakeHttpClient();
		$http->onTelegram(
			'getWebhookInfo',
			static fn (): array => ['ok' => true, 'result' => ['url' => '', 'pending_update_count' => 0]],
		);
		self::assertSame(
			['url' => '', 'pending_update_count' => 0],
			(new BotApi($http, self::TOKEN))->getWebhookInfo(),
		);
	}
}
