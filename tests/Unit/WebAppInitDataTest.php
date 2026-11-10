<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Telegram\WebAppInitData;
use P1Bot\View\WebAppUrl;
use PHPUnit\Framework\TestCase;

class WebAppInitDataTest extends TestCase {

	protected const TOKEN = '123456:TESTTOKEN';

	protected function initData(array $user, int $auth_date, ?string $token = null): string {
		return WebAppInitData::sign([
			'auth_date' => (string) $auth_date,
			'query_id' => 'AAE',
			'user' => (string) json_encode($user, JSON_UNESCAPED_UNICODE),
		], $token ?? self::TOKEN);
	}

	public function testValidSignature(): void {
		$now = 1_700_000_000;
		$raw = $this->initData(['id' => 42, 'first_name' => 'Mata', 'username' => 'mata'], $now);
		$parsed = WebAppInitData::parse($raw, self::TOKEN, $now);
		self::assertSame(42, $parsed['user_id']);
		self::assertSame('mata', $parsed['username']);
		self::assertSame('Mata', $parsed['first_name']);
		self::assertSame($now, $parsed['auth_date']);
	}

	public function testRejectsTamperedHash(): void {
		$now = 1_700_000_000;
		$raw = $this->initData(['id' => 42, 'first_name' => 'A'], $now);
		$raw = preg_replace('/hash=[0-9a-f]+$/', 'hash=' . str_repeat('ab', 32), $raw) ?? $raw;
		$this->expectException(ValidationError::class);
		WebAppInitData::parse($raw, self::TOKEN, $now);
	}

	public function testRejectsWrongBotToken(): void {
		$now = 1_700_000_000;
		$raw = $this->initData(['id' => 42, 'first_name' => 'A'], $now);
		$this->expectException(ValidationError::class);
		WebAppInitData::parse($raw, 'other-token', $now);
	}

	public function testRejectsStaleAuthDate(): void {
		$now = 1_700_000_000;
		$raw = $this->initData(['id' => 42, 'first_name' => 'A'], $now - 3601);
		$this->expectException(ValidationError::class);
		WebAppInitData::parse($raw, self::TOKEN, $now);
	}

	public function testRejectsMissingUser(): void {
		$now = 1_700_000_000;
		$raw = WebAppInitData::sign(['auth_date' => (string) $now], self::TOKEN);
		$this->expectException(ValidationError::class);
		WebAppInitData::parse($raw, self::TOKEN, $now);
	}

	public function testPickerUrlFromWebhook(): void {
		self::assertSame(
			'https://pop1.example.com/webapp/picker.html',
			WebAppUrl::pickerPage('https://pop1.example.com/webhook.php'),
		);
		self::assertSame(
			'https://pop1.example.com/p1/webapp/picker.html?ctx=abc',
			WebAppUrl::picker('https://pop1.example.com/p1/webhook.php', 'abc'),
		);
		self::assertNull(WebAppUrl::pickerPage(''));
		self::assertNull(WebAppUrl::pickerPage('http://insecure.example/webhook.php'));
	}
}
