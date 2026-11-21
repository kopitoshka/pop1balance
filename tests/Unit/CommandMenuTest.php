<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
	bin/set_webhook.php menus: /create_tournament is in private (and owner) only, not in the
	default/group menu; /new is gone.
*/
class CommandMenuTest extends TestCase {

	public function testMenusOfferTheFormAndNotNew(): void {
		$src = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/set_webhook.php');
		[$before_private, $rest] = explode('$private = [', $src, 2);
		self::assertStringNotContainsString(
			"'command' => 'create_tournament'",
			$before_private,
			'/create_tournament is not in the default or group menu',
		);
		self::assertStringContainsString("'command' => 'create_tournament'", $rest);
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
		self::assertStringNotContainsString("'command' => 'players_import'", $general);
		self::assertSame(1, substr_count($owner, "'command' => 'players_import'"));
		self::assertLessThan(
			strpos($owner, "'command' => 'players_import'"),
			strpos($owner, "'command' => 'addplayer'"),
			'/players_import follows /addplayer in the owner menu',
		);
		self::assertStringContainsString("\$api->setMyCommands(\$owner, ['type' => 'chat', 'chat_id' => \$owner_tg_id])", $owner);
	}

	public function testStartAndInfoAreOffTheMenusAndHelpLevelIsOn(): void {
		$src = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/set_webhook.php');
		self::assertStringNotContainsString("'command' => 'start'", $src);
		self::assertStringNotContainsString("'command' => 'info'", $src);
		self::assertSame(2, substr_count($src, "'command' => 'help_level'"), 'common and private menus');
		self::assertSame(2, substr_count($src, "'command' => 'help'"), 'common and private menus');
	}
}
