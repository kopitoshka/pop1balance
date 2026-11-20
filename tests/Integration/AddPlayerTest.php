<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Tests\Support\IntegrationTestCase;
use P1Bot\View\Messages;

class AddPlayerTest extends IntegrationTestCase {

	public function testAnyPrivateUserGetsPickerKeyboard(): void {
		$vasya = $this->player('vasya');
		$this->dispatch($this->privateMessage($vasya, '/addplayer'));
		$sent = $this->http->sent('sendMessage');
		$last = end($sent);
		self::assertStringContainsString('добавлю их в базу', (string) ($last['text'] ?? ''));
		$btn = $last['reply_markup']['keyboard'][0][0] ?? null;
		self::assertIsArray($btn);
		self::assertSame('👤 Выбрать игроков', $btn['text']);
		self::assertSame(1, $btn['request_users']['request_id']);
		self::assertFalse($btn['request_users']['user_is_bot']);
		self::assertSame(10, $btn['request_users']['max_quantity']);
		self::assertTrue($btn['request_users']['request_name']);
		self::assertTrue($btn['request_users']['request_username']);
	}

	public function testAdminAlsoGetsPicker(): void {
		$this->dispatch($this->privateMessage($this->admin, '/addplayer'));
		self::assertStringContainsString('добавлю их в базу', $this->http->lastText());
	}

	public function testPrivateHelpListsAddplayerForEveryone(): void {
		$vasya = $this->player('help_user');
		$this->withoutSharedOwnerGroup($vasya);
		$this->dispatch($this->privateMessage($vasya, '/help'));
		self::assertStringContainsString('/addplayer', $this->http->lastText());
		self::assertStringNotContainsString('Только для главного админа', $this->http->lastText());
		$this->http->reset();
		$this->dispatch($this->privateMessage($this->owner, '/help'));
		$owner_help = $this->http->lastText();
		self::assertStringContainsString('/addplayer', $owner_help);
		self::assertStringContainsString('Только для главного админа', $owner_help);
		// The owner-only block does not list /addplayer: the picker is for everyone, the argument
		// forms are in the tournament creators' block.
		$owner_block = (string) strstr($owner_help, 'Только для главного админа');
		self::assertStringNotContainsString('/addplayer', (string) strstr($owner_block, '📊', true));
	}

	public function testOldAdduserIsUnknown(): void {
		$this->dispatch($this->privateMessage($this->player('old_cmd'), '/adduser'));
		self::assertSame('Такой команды я не знаю 🤔 Подсказка: /help', $this->http->lastText());
		self::assertStringNotContainsString('/adduser', \P1Bot\View\Texts::help('pop1_bot', true, true));
	}

	public function testUsersSharedUpsertsForNonOwner(): void {
		$vasya = $this->player('picker');
		$ph = $this->insertPlaceholder('newbie');
		$raw = [
			'update_id' => 501,
			'message' => [
				'message_id' => 80,
				'chat' => ['id' => $this->tgIdOf($vasya), 'type' => 'private'],
				'from' => [
					'id' => $this->tgIdOf($vasya),
					'is_bot' => false,
					'first_name' => 'Vasya',
					'username' => 'picker',
				],
				'date' => 0,
				'users_shared' => [
					'request_id' => 1,
					'users' => [
						[
							'user_id' => 4242,
							'first_name' => 'New',
							'last_name' => 'Bie',
							'username' => 'newbie',
						],
						[
							'user_id' => 4243,
							'first_name' => 'Безника',
						],
					],
				],
			],
		];
		$this->dispatch($raw);
		$text = $this->http->lastText();
		self::assertStringContainsString('Готово', $text);
		self::assertStringContainsString('@newbie', $text);
		self::assertStringContainsString('слила', $text);
		self::assertStringContainsString('Безника', $text);
		$merged = $this->app->players->findByTelegramId(4242);
		self::assertNotNull($merged);
		self::assertSame($ph->id, $merged->id);
		$sent = $this->http->sent('sendMessage');
		$last = end($sent);
		self::assertTrue($last['reply_markup']['remove_keyboard'] ?? false);
	}

	public function testGroupAddplayerRedirectsToPrivate(): void {
		$this->dispatch($this->groupMessage($this->player('grp_user'), '/addplayer'));
		$text = $this->http->lastText();
		self::assertTrue(
			str_contains($text, Messages::GROUP_REDIRECT)
			|| str_contains($text, 'личк'),
			'expected private redirect, got: ' . $text,
		);
	}
}
