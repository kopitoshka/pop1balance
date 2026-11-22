<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use P1Bot\Support\ImageStore;
use P1Bot\View\WebAppUrl;
use PHPUnit\Framework\TestCase;

class MediaBaseUrlTest extends TestCase {

	public function testDerivedFromWebhookUrl(): void {
		self::assertSame('https://bot.example.org/media', WebAppUrl::mediaBase('https://bot.example.org/webhook.php'));
		self::assertSame('https://bot.example.org/p1/media', WebAppUrl::mediaBase('https://bot.example.org/p1/webhook.php'));
		self::assertSame('https://bot.example.org:8443/media', WebAppUrl::mediaBase('https://bot.example.org:8443/webhook.php'));
	}

	public function testOverrideWins(): void {
		self::assertSame(
			'https://cdn.example.net/pics',
			WebAppUrl::mediaBase('https://bot.example.org/webhook.php', ' https://cdn.example.net/pics/ '),
		);
		self::assertSame('https://cdn.example.net', WebAppUrl::mediaBase('', 'https://cdn.example.net'));
	}

	public function testUnusableIsNull(): void {
		self::assertNull(WebAppUrl::mediaBase(''));
		self::assertNull(WebAppUrl::mediaBase('http://bot.example.org/webhook.php'));
		self::assertNull(WebAppUrl::mediaBase('https://bot.example.org/webhook.php', 'ftp://x.example.org'));
		self::assertNull(WebAppUrl::mediaBase('https://bot.example.org/webhook.php', 'not a url'));
	}

	public function testNamePatternAcceptsOnlyGeneratedNames(): void {
		self::assertTrue(ImageStore::isName(str_repeat('a', 32) . '.jpg'));
		self::assertTrue(ImageStore::isName(str_repeat('0', 32) . '.webp'));
		foreach ([null, '', '../' . str_repeat('a', 32) . '.jpg', str_repeat('a', 32) . '.php', 'tournament-1.jpg', str_repeat('A', 32) . '.jpg', str_repeat('a', 32) . ".jpg\n"] as $bad) {
			self::assertFalse(ImageStore::isName($bad));
		}
	}

	public function testMegabytesText(): void {
		self::assertSame('5 МБ', ImageStore::megabytes(5242880));
		self::assertSame('1,5 МБ', ImageStore::megabytes(1572864));
	}
}
