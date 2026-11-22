<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use P1Bot\Telegram\CommandMenu;
use PHPUnit\Framework\TestCase;

/**
	The «/» menus (CommandMenu, set up by bin/set_webhook.php): /create_tournament is in private only,
	not in the default/group menu; /new is gone; the owner commands and /su are per-chat extras.
*/
class CommandMenuTest extends TestCase {

	/**
		@param list<array{command: string, description: string}> $menu
		@return list<string>
	*/
	protected static function names(array $menu): array {
		return array_map(static fn (array $c): string => $c['command'], $menu);
	}

	public function testMenusOfferTheFormAndNotNew(): void {
		self::assertNotContains('create_tournament', self::names(CommandMenu::common()));
		self::assertContains('create_tournament', self::names(CommandMenu::privateChats()));
		foreach ([CommandMenu::common(), CommandMenu::privateChats(), CommandMenu::forPrivateChat(true, true)] as $menu) {
			self::assertNotContains('new', self::names($menu));
		}
	}

	public function testPlayersReplacesLevelsAndManageCommandsAreButtons(): void {
		self::assertContains('players', self::names(CommandMenu::common()));
		self::assertContains('players', self::names(CommandMenu::privateChats()));
		foreach (['levels', 'edit', 'cancel', 'adduser'] as $gone) {
			self::assertNotContains($gone, self::names(CommandMenu::forPrivateChat(true, true)), $gone);
		}
	}

	public function testOwnerCommandsAndSuAreOnlyInTheirOwnMenus(): void {
		foreach ([CommandMenu::common(), CommandMenu::privateChats()] as $menu) {
			foreach (['addplayer', 'players_import', 'su'] as $hidden) {
				self::assertNotContains($hidden, self::names($menu), $hidden);
			}
		}
		$super = self::names(CommandMenu::forPrivateChat(true, true));
		self::assertLessThan(array_search('players_import', $super, true), array_search('addplayer', $super, true));
		self::assertContains('su', $super);

		$mode_off = self::names(CommandMenu::forPrivateChat(false, true));
		self::assertContains('su', $mode_off);
		self::assertNotContains('addplayer', $mode_off);
		self::assertNotContains('players_import', $mode_off);

		self::assertSame(self::names(CommandMenu::privateChats()), self::names(CommandMenu::forPrivateChat(false, false)));
	}

	public function testStartAndInfoAreOffTheMenusAndHelpLevelIsLast(): void {
		foreach ([CommandMenu::common(), CommandMenu::privateChats(), CommandMenu::forPrivateChat(true, true)] as $menu) {
			$names = self::names($menu);
			self::assertNotContains('start', $names);
			self::assertNotContains('info', $names);
			self::assertContains('help', $names);
			self::assertSame('help_level', end($names));
		}
	}

	public function testChatScopeIsThePrivateChatOfTheUser(): void {
		self::assertSame(['type' => 'chat', 'chat_id' => 77], CommandMenu::chatScope(77));
	}

	public function testSetWebhookUsesTheMenus(): void {
		$src = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/set_webhook.php');
		self::assertStringContainsString('CommandMenu::common()', $src);
		self::assertStringContainsString('CommandMenu::privateChats()', $src);
		self::assertStringContainsString('CommandMenu::forPrivateChat(true, true)', $src);
		self::assertStringContainsString("['type' => 'all_group_chats']", $src);
		self::assertStringContainsString("['type' => 'all_private_chats']", $src);
	}
}
