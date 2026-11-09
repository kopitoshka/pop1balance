<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use P1Bot\Support\Redact;
use PHPUnit\Framework\TestCase;

class RedactTest extends TestCase {

	public function testHidesKeysTokensAndTruncates(): void {
		$text = "key sk-proj-ABCDEFGH12345678 token 123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw secret mysecret\nend";
		$out = Redact::text($text, ['mysecret']);
		self::assertStringNotContainsString('ABCDEFGH', $out);
		self::assertStringNotContainsString('AAHdq', $out);
		self::assertStringNotContainsString('mysecret', $out);
		self::assertStringContainsString('sk-***', $out);
		self::assertStringNotContainsString("\n", $out);

		self::assertSame('ab…', Redact::text('abcdef', [], 2));
	}
}
