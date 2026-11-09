<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use P1Bot\App\WebhookGate;
use PHPUnit\Framework\TestCase;

class WebhookGateTest extends TestCase {

	public function testRejectsWrongMethod(): void {
		[$status, $reason, $payload] = WebhookGate::check('GET', null, 'topsecret', '');
		self::assertSame(405, $status);
		self::assertStringContainsString('GET', $reason);
		self::assertNull($payload);
	}

	public function testMissingAndWrongSecretAreDistinguishedWithoutLeakingIt(): void {
		[$status, $missing] = WebhookGate::check('POST', null, 'topsecret', '{}');
		self::assertSame(403, $status);
		self::assertStringContainsString('missing', $missing);

		[$status, $wrong] = WebhookGate::check('POST', 'guess', 'topsecret', '{}');
		self::assertSame(403, $status);
		self::assertStringContainsString('wrong', $wrong);

		[$status, $empty] = WebhookGate::check('POST', '', 'topsecret', '{}');
		self::assertSame(403, $status);
		self::assertStringContainsString('wrong', $empty);

		[$status, $unset] = WebhookGate::check('POST', 'x', '', '{}');
		self::assertSame(403, $status);
		self::assertStringContainsString('empty', $unset);

		foreach ([$missing, $wrong, $empty, $unset] as $reason) {
			self::assertStringNotContainsString('topsecret', $reason);
			self::assertStringNotContainsString('guess', $reason);
		}
	}

	public function testBadJson(): void {
		[$status, $reason, $payload] = WebhookGate::check('POST', 'topsecret', 'topsecret', '{oops');
		self::assertSame(400, $status);
		self::assertStringContainsString('bad JSON', $reason);
		self::assertNull($payload);
	}

	public function testAccepts(): void {
		[$status, , $payload] = WebhookGate::check('POST', 'topsecret', 'topsecret', '{"update_id":5}');
		self::assertSame(200, $status);
		self::assertSame(['update_id' => 5], $payload);
	}
}
