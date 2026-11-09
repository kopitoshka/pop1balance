<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Tests\Support\IntegrationTestCase;

class UpdateRouterTest extends IntegrationTestCase {

	public function testIgnoresMessagesNotAddressedToBot(): void {
		$vasya = $this->player('vasya');
		$this->dispatch($this->groupMessage($vasya, 'всем привет, кто играет сегодня?'));
		$this->dispatch($this->groupMessage($vasya, '@petya го в субботу'));
		$this->dispatch($this->groupMessage($vasya, '/help@other_bot'));
		self::assertSame([], $this->http->requests);
	}

	public function testMentionGetsReply(): void {
		$vasya = $this->player('vasya');
		$update = $this->groupMessage($vasya, '@' . self::BOT_USERNAME . ' привет');
		$this->dispatch($update);

		$sent = $this->http->sent('sendMessage');
		self::assertCount(1, $sent);
		self::assertSame(self::CHAT_ID, $sent[0]['chat_id']);
		self::assertSame($update['message']['message_id'], $sent[0]['reply_parameters']['message_id']);
	}

	public function testReplyToBotMessageGetsReply(): void {
		$vasya = $this->player('vasya');
		$this->dispatch($this->groupMessage($vasya, 'а когда?', [
			'reply_to_message' => [
				'message_id' => 1,
				'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Bot'],
			],
		]));
		self::assertCount(1, $this->http->sent('sendMessage'));
	}

	public function testPrivateHelpIsAnswered(): void {
		$vasya = $this->player('vasya');
		$this->dispatch($this->privateMessage($vasya, '/help'));
		self::assertCount(1, $this->http->sent('sendMessage'));
		self::assertSame($this->tgIdOf($vasya), $this->http->sent('sendMessage')[0]['chat_id']);
		self::assertStringContainsString('Пиши мне обычным языком', $this->http->lastText());
		self::assertSame(
			[self::CHAT_ID],
			array_keys($this->app->chats->listActiveGroups()),
			'a private chat is not a group',
		);
	}

	public function testUsernamePlaceholderIsAttachedWhenUserWrites(): void {
		$placeholder = $this->app->players->ensureByUsername('newbie', $this->clock->now());
		$this->dispatch(['update_id' => 900, 'message' => [
			'message_id' => 1,
			'chat' => ['id' => self::CHAT_ID, 'type' => 'supergroup', 'title' => 'P1 турниры'],
			'from' => ['id' => 4242, 'is_bot' => false, 'first_name' => 'Новичок', 'username' => 'Newbie'],
			'text' => '/help',
			'entities' => [['type' => 'bot_command', 'offset' => 0, 'length' => 5]],
		]]);
		$player = $this->app->players->findByTelegramId(4242);
		self::assertNotNull($player);
		self::assertSame($placeholder->id, $player->id);
	}

	public function testChatAdminsAreSyncedAndCached(): void {
		$calls = 0;
		$this->http->onTelegram('getChatAdministrators', function () use (&$calls): array {
			$calls++;
			return ['ok' => true, 'result' => [
				[
					'status' => 'creator',
					'user' => ['id' => 7001, 'is_bot' => false, 'first_name' => 'Модератор', 'username' => 'moder'],
				],
				[
					'status' => 'administrator',
					'user' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Bot'],
				],
			]];
		});
		$vasya = $this->player('vasya');

		// Admin rights are synced per group when someone writes in private.
		$this->dispatch($this->privateMessage($vasya, '/help'));
		$this->dispatch($this->privateMessage($vasya, '/help'));
		self::assertSame(1, $calls, 'cached');

		$moder = $this->app->players->findByTelegramId(7001);
		self::assertNotNull($moder);
		self::assertTrue($this->app->access->isChatAdmin(self::CHAT_ID, $moder->id));
		self::assertFalse($this->app->access->isChatAdmin(self::CHAT_ID, $vasya->id));
		self::assertFalse(
			$this->app->access->isChatAdmin(self::CHAT_ID, $this->admin->id),
			'replaced by Telegram list',
		);
		self::assertTrue(
			$this->app->access->isChatAdmin(self::CHAT_ID, $this->owner->id),
			'owner from .env is always admin',
		);

		$this->clock->advance('+11 minutes');
		$this->dispatch($this->privateMessage($vasya, '/help'));
		self::assertSame(2, $calls, 'refreshed after TTL');
	}

	public function testBotAddedToGroupGreets(): void {
		$this->dispatch(['update_id' => 1, 'my_chat_member' => [
			'chat' => ['id' => -100777, 'type' => 'supergroup', 'title' => 'Новая группа'],
			'old_chat_member' => ['status' => 'left'],
			'new_chat_member' => ['status' => 'member'],
		]]);
		self::assertTrue($this->app->chats->isActive(-100777));
		self::assertStringContainsString('Population: One', $this->http->lastText());

		$this->dispatch(['update_id' => 2, 'my_chat_member' => [
			'chat' => ['id' => -100777, 'type' => 'supergroup', 'title' => 'Новая группа'],
			'old_chat_member' => ['status' => 'member'],
			'new_chat_member' => ['status' => 'kicked'],
		]]);
		self::assertFalse($this->app->chats->isActive(-100777));
	}

	public function testEnsureOwnerCreatesDefaultBalance(): void {
		$this->app->ensureOwner();
		self::assertNotNull($this->app->balances->findDefault());
	}
}
