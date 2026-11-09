<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use P1Bot\Telegram\Message;
use P1Bot\Telegram\Update;
use PHPUnit\Framework\TestCase;

class MessageTest extends TestCase {

	public function testMentionWithUtf16Offsets(): void {
		// "🔥 " is 3 UTF-16 units (surrogate pair + space), so the mention starts at offset 3.
		$msg = $this->message('🔥 @Pop1_Bot запиши нас', [['type' => 'mention', 'offset' => 3, 'length' => 9]]);

		self::assertTrue($msg->mentions('pop1_bot'));
		self::assertTrue($msg->isAddressedTo('pop1_bot', 42));
		self::assertSame('🔥 запиши нас', $msg->textWithoutMention('pop1_bot'));
		self::assertSame([], $msg->mentionedUsers('pop1_bot'));
	}

	public function testOtherMentionsAreNotAddressedToBot(): void {
		$msg = $this->message('@vasya привет', [['type' => 'mention', 'offset' => 0, 'length' => 6]]);
		self::assertFalse($msg->isAddressedTo('pop1_bot', 42));
		self::assertSame([['text' => '@vasya', 'username' => 'vasya']], $msg->mentionedUsers('pop1_bot'));
	}

	public function testReplyToBotIsAddressed(): void {
		$msg = Message::fromArray([
			'message_id' => 5,
			'chat' => ['id' => -100, 'type' => 'group'],
			'from' => ['id' => 1, 'first_name' => 'A'],
			'text' => 'да, давай',
			'reply_to_message' => ['message_id' => 4, 'from' => ['id' => 42, 'is_bot' => true, 'first_name' => 'Bot']],
		]);
		self::assertTrue($msg->isAddressedTo('pop1_bot', 42));
		self::assertSame(4, $msg->reply_to_message_id);
	}

	public function testPrivateChatIsNeverAddressed(): void {
		$msg = Message::fromArray([
			'message_id' => 1,
			'chat' => ['id' => 7, 'type' => 'private'],
			'from' => ['id' => 7, 'first_name' => 'A'],
			'text' => '@pop1_bot hi',
			'entities' => [['type' => 'mention', 'offset' => 0, 'length' => 9]],
		]);
		self::assertFalse($msg->isAddressedTo('pop1_bot', 42));
	}

	public function testCommandParsing(): void {
		$msg = $this->message(
			'/new@Pop1_Bot 23.11 20:00 | Кубок',
			[['type' => 'bot_command', 'offset' => 0, 'length' => 13]],
		);
		self::assertSame(['name' => 'new', 'bot' => 'Pop1_Bot', 'args' => '23.11 20:00 | Кубок'], $msg->command());

		$plain = $this->message('/HELP', [['type' => 'bot_command', 'offset' => 0, 'length' => 5]]);
		self::assertSame(['name' => 'help', 'bot' => null, 'args' => ''], $plain->command());

		self::assertNull($this->message('привет', [])->command());
	}

	public function testTextMention(): void {
		$msg = $this->message('добавь Васю', [[
			'type' => 'text_mention',
			'offset' => 7,
			'length' => 4,
			'user' => ['id' => 99, 'is_bot' => false, 'first_name' => 'Вася'],
		]]);
		$mentions = $msg->mentionedUsers('pop1_bot');
		self::assertSame(99, $mentions[0]['user']->id);
		self::assertSame('Васю', $msg->entityText($msg->entities[0]));
	}

	public function testBotMembershipChange(): void {
		$update = Update::fromArray(['update_id' => 1, 'my_chat_member' => [
			'chat' => ['id' => -100, 'type' => 'supergroup', 'title' => 'G'],
			'old_chat_member' => ['status' => 'left'],
			'new_chat_member' => ['status' => 'member'],
		]]);
		self::assertSame(
			['chat_id' => -100, 'title' => 'G', 'type' => 'supergroup', 'is_member' => true, 'was_member' => false],
			$update->botMembershipChange(),
		);
	}

	/**
		@param list<array<string, mixed>> $entities
	*/
	protected function message(string $text, array $entities): Message {
		return Message::fromArray([
			'message_id' => 1,
			'chat' => ['id' => -100, 'type' => 'supergroup', 'title' => 'G'],
			'from' => ['id' => 1, 'first_name' => 'A'],
			'text' => $text,
			'entities' => $entities,
		]);
	}
	public function testUsersSharedWithNames(): void {
		$msg = Message::fromArray([
			'message_id' => 9,
			'chat' => ['id' => 1000, 'type' => 'private'],
			'from' => ['id' => 1000, 'first_name' => 'Owner'],
			'users_shared' => [
				'request_id' => 1,
				'users' => [
					['user_id' => 42, 'first_name' => 'Вася', 'last_name' => 'П', 'username' => 'vasya'],
					['user_id' => 43, 'first_name' => 'Безника'],
				],
			],
		]);
		self::assertNotNull($msg->users_shared);
		self::assertSame(1, $msg->users_shared['request_id']);
		self::assertSame(42, $msg->users_shared['users'][0]['id']);
		self::assertSame('vasya', $msg->users_shared['users'][0]['username']);
		self::assertNull($msg->users_shared['users'][1]['username']);
		self::assertSame('Безника', $msg->users_shared['users'][1]['first_name']);
	}

	public function testUsersSharedLegacyUserIds(): void {
		$msg = Message::fromArray([
			'message_id' => 9,
			'chat' => ['id' => 1000, 'type' => 'private'],
			'from' => ['id' => 1000, 'first_name' => 'Owner'],
			'users_shared' => ['request_id' => 1, 'user_ids' => [7, 8]],
		]);
		self::assertSame([7, 8], array_column($msg->users_shared['users'], 'id'));
	}


}
