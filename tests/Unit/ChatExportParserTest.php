<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use InvalidArgumentException;
use P1Bot\App\ChatExportParser;
use PHPUnit\Framework\TestCase;

class ChatExportParserTest extends TestCase {

	public function testDeriveSupergroupChatId(): void {
		$p = new ChatExportParser();
		self::assertSame(-1001234567890, $p->deriveChatId(1234567890, 'private_supergroup'));
		self::assertSame(-100987, $p->deriveChatId(987, 'public_supergroup'));
		self::assertSame(-1001, $p->deriveChatId(1, 'channel'));
	}

	public function testDeriveBasicGroupChatId(): void {
		$p = new ChatExportParser();
		self::assertSame(-555, $p->deriveChatId(555, 'private_group'));
		self::assertSame(-10, $p->deriveChatId(10, 'group'));
	}

	public function testDeriveKeepsNegativeExportId(): void {
		$p = new ChatExportParser();
		self::assertSame(-100123, $p->deriveChatId(-100123, 'private_supergroup'));
		self::assertSame(-42, $p->deriveChatId(-42, 'private_group'));
	}

	public function testPrivateSupergroupDerivationMatchesBotApi(): void {
		$p = new ChatExportParser();
		self::assertSame(-1002119660082, $p->deriveChatId(2119660082, 'private_supergroup'));
	}

	public function testParsePeerIdOnlyUsers(): void {
		$p = new ChatExportParser();
		self::assertSame(123456, $p->parsePeerId('user123456'));
		self::assertNull($p->parsePeerId('channel999'));
		self::assertNull($p->parsePeerId('chat1'));
		self::assertNull($p->parsePeerId(''));
	}

	public function testCollectFromIdAndActorId(): void {
		$p = new ChatExportParser();
		$ids = $p->collectUserIds([
			['id' => 1, 'type' => 'message', 'from_id' => 'user111', 'from' => 'A'],
			['id' => 2, 'type' => 'service', 'actor_id' => 'user222', 'action' => 'join_group_by_link'],
			['id' => 3, 'type' => 'service', 'actor_id' => 'user111', 'action' => 'invite_members'],
			['id' => 4, 'type' => 'message', 'from_id' => 'channel9', 'from' => 'Channel'],
			['id' => 5, 'type' => 'message', 'from_id' => 'user333', 'from' => 'C'],
		]);
		sort($ids);
		self::assertSame([111, 222, 333], $ids);
	}

	public function testParseFullExport(): void {
		$p = new ChatExportParser();
		$result = $p->parse([
			'id' => 1234567890,
			'type' => 'private_supergroup',
			'name' => 'P1 турниры',
			'messages' => [
				['from_id' => 'user10', 'from' => 'X'],
				['actor_id' => 'user20', 'action' => 'join_group_by_link'],
			],
		]);
		self::assertSame(1234567890, $result['export_id']);
		self::assertSame(-1001234567890, $result['chat_id']);
		self::assertSame('P1 турниры', $result['name']);
		self::assertSame([10, 20], $result['user_ids']);
	}

	public function testParseRequiresIdAndMessages(): void {
		$p = new ChatExportParser();
		$this->expectException(InvalidArgumentException::class);
		$p->parse(['type' => 'private_supergroup', 'messages' => []]);
	}

	public function testParseFileStreamsLargeExport(): void {
		$path = sys_get_temp_dir() . '/p1_export_' . getmypid() . '.json';
		$fh = fopen($path, 'wb');
		fwrite($fh, "{\n \"name\": \"Наследие\",\n \"type\": \"private_supergroup\",\n \"id\": 2119660082,\n \"messages\": [\n");
		for ($i = 0; $i < 500; $i++) {
			fwrite($fh, json_encode([
				'id' => $i,
				'type' => 'message',
				'from_id' => 'user' . (1000 + ($i % 7)),
				'from' => 'U' . $i,
				'text' => str_repeat('x', 200),
			], JSON_UNESCAPED_UNICODE) . ",\n");
		}
		fwrite($fh, json_encode([
			'id' => 9999,
			'type' => 'service',
			'actor_id' => 'user777',
			'action' => 'join_group_by_link',
		], JSON_UNESCAPED_UNICODE) . ",\n");
		fwrite($fh, json_encode([
			'id' => 10000,
			'type' => 'message',
			'from_id' => 'channel55',
			'from' => 'Chan',
		], JSON_UNESCAPED_UNICODE) . "\n]\n}\n");
		fclose($fh);

		$p = new ChatExportParser();
		$result = $p->parseFile($path);
		@unlink($path);

		self::assertSame(2119660082, $result['export_id']);
		self::assertSame('private_supergroup', $result['type']);
		self::assertSame('Наследие', $result['name']);
		self::assertSame(-1002119660082, $result['chat_id']);
		self::assertContains(777, $result['user_ids']);
		self::assertContains(1000, $result['user_ids']);
		self::assertNotContains(55, $result['user_ids']);
		self::assertCount(8, $result['user_ids']);
	}
}
