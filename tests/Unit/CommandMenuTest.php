<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
	bin/set_webhook.php menus: /create_tournament is there (common, groups, private), /new is gone.
*/
class CommandMenuTest extends TestCase {

	public function testMenusOfferTheFormAndNotNew(): void {
		$src = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/set_webhook.php');
		self::assertSame(2, substr_count($src, "'command' => 'create_tournament'"));
		self::assertStringNotContainsString("'command' => 'new'", $src);
	}
}
