<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Domain\AccessPolicy;
use P1Bot\Tests\Support\IntegrationTestCase;

class AddUserTest extends IntegrationTestCase {

	public function testOwnerGetsPickerKeyboard(): void {
		$this->dispatch($this->privateMessage($this->owner, '/adduser'));
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

	public function testNonOwnerDenied(): void {
		$this->dispatch($this->privateMessage($this->admin, '/adduser'));
		self::assertStringContainsString(AccessPolicy::OWNER_ONLY, $this->http->lastText());
	}

	public function testOwnerHelpListsAdduser(): void {
		$this->dispatch($this->privateMessage($this->owner, '/help'));
		self::assertStringContainsString('/adduser', $this->http->lastText());
		$this->http->reset();
		$this->dispatch($this->privateMessage($this->admin, '/help'));
		self::assertStringNotContainsString('/adduser', $this->http->lastText());
	}

	public function testUsersSharedUpsertsAndRemovesKeyboard(): void {
		$ph = $this->insertPlaceholder('vasya');
		$raw = [
			'update_id' => 501,
			'message' => [
				'message_id' => 80,
				'chat' => ['id' => self::OWNER_TG_ID, 'type' => 'private'],
				'from' => [
					'id' => self::OWNER_TG_ID,
					'is_bot' => false,
					'first_name' => 'Андрей',
					'username' => 'andrey',
				],
				'date' => 0,
				'users_shared' => [
					'request_id' => 1,
					'users' => [
						[
							'user_id' => 4242,
							'first_name' => 'Вася',
							'last_name' => 'П',
							'username' => 'vasya',
						],
						[
							'user_id' => 4243,
							'first_name' => 'Безника',
						],
						[
							'user_id' => self::OWNER_TG_ID,
							'first_name' => 'Андрей',
							'username' => 'andrey',
						],
					],
				],
			],
		];
		$this->dispatch($raw);
		$text = $this->http->lastText();
		self::assertStringContainsString('Готово', $text);
		self::assertStringContainsString('@vasya', $text);
		self::assertStringContainsString('слила', $text);
		self::assertStringContainsString('Безника', $text);
		self::assertStringContainsString('новая', $text);
		self::assertStringContainsString('обновила', $text);

		$merged = $this->app->players->findByTelegramId(4242);
		self::assertNotNull($merged);
		self::assertSame($ph->id, $merged->id);
		$fresh = $this->app->players->findByTelegramId(4243);
		self::assertNotNull($fresh);
		self::assertSame('Безника', $fresh->display_name);

		$sent = $this->http->sent('sendMessage');
		$last = end($sent);
		self::assertTrue($last['reply_markup']['remove_keyboard'] ?? false);
	}

	public function testUnknownCommandStillUnknownForRandomUser(): void {
		$vasya = $this->player('nobody_cmd');
		$this->dispatch($this->privateMessage($vasya, '/adduser'));
		// non-owner who is not admin: still OWNER_ONLY because supports includes adduser
		self::assertStringContainsString(AccessPolicy::OWNER_ONLY, $this->http->lastText());
	}
}
