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

	public function testPlayersReplacesLevelsAndManageCommandsAreButtons(): void {
		$src = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/set_webhook.php');
		self::assertSame(2, substr_count($src, "'command' => 'players'"), 'common and private menus');
		foreach (['levels', 'edit', 'cancel', 'adduser'] as $gone) {
			self::assertStringNotContainsString("'command' => '" . $gone . "'", $src, $gone);
		}
	}

	public function testAddplayerIsShownOnlyInOwnerMenu(): void {
		$src = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/set_webhook.php');
		[$general, $owner] = explode("if (\$owner_tg_id > 0) {", $src, 2);
		self::assertStringNotContainsString("'command' => 'addplayer'", $general);
		self::assertSame(1, substr_count($owner, "'command' => 'addplayer'"));
		self::assertStringContainsString("\$api->setMyCommands(\$owner, ['type' => 'chat', 'chat_id' => \$owner_tg_id])", $owner);
	}
}
