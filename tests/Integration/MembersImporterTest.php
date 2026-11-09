<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\App\MembersImporter;
use P1Bot\Repository\ChatRepository;
use P1Bot\Telegram\BotApi;
use P1Bot\Tests\Support\IntegrationTestCase;

class MembersImporterTest extends IntegrationTestCase {

	/**
		@param array<int, array<string, mixed>> $by_user_id user_id => ChatMember result
	*/
	protected function importerWithMembers(array $by_user_id): MembersImporter {
		$this->http->onTelegram('getChat', static fn (): array => [
			'ok' => true,
			'result' => ['id' => self::CHAT_ID, 'type' => 'supergroup', 'title' => 'Import chat'],
		]);
		$this->http->onTelegram('getChatMember', function (array $json) use ($by_user_id): array {
			$user_id = (int) $json['user_id'];
			if (!isset($by_user_id[$user_id])) {
				return [
					'ok' => false,
					'error_code' => 400,
					'description' => 'Bad Request: user not found',
				];
			}
			return ['ok' => true, 'result' => $by_user_id[$user_id]];
		});
		$api = new BotApi($this->http, self::BOT_ID . ':TEST');
		return new MembersImporter(
			$api,
			$this->app->players,
			new ChatRepository($this->db),
			$this->clock,
			static function (int $seconds): void {
			},
			1000,
		);
	}

	public function testImportsNewUpdatesSkipsLeftAndBots(): void {
		$existing = $this->player('oldie');
		$existing_tg = $this->tgIdOf($existing);

		$importer = $this->importerWithMembers([
			$existing_tg => [
				'status' => 'administrator',
				'user' => [
					'id' => $existing_tg,
					'is_bot' => false,
					'username' => 'oldie',
					'first_name' => 'Old',
					'last_name' => 'Ie',
				],
			],
			3001 => [
				'status' => 'member',
				'user' => [
					'id' => 3001,
					'is_bot' => false,
					'username' => 'newbie',
					'first_name' => 'New',
					'last_name' => 'Bie',
				],
			],
			3002 => [
				'status' => 'left',
				'user' => ['id' => 3002, 'is_bot' => false, 'first_name' => 'Gone'],
			],
			3003 => [
				'status' => 'member',
				'user' => [
					'id' => 3003,
					'is_bot' => true,
					'username' => 'some_bot',
					'first_name' => 'Bot',
				],
			],
			3004 => [
				'status' => 'kicked',
				'user' => ['id' => 3004, 'is_bot' => false, 'first_name' => 'Ban'],
			],
		]);

		$stats = $importer->import(self::CHAT_ID, [$existing_tg, 3001, 3002, 3003, 3004], false);

		self::assertSame(5, $stats['total_ids']);
		self::assertSame(1, $stats['imported_new']);
		self::assertSame(1, $stats['updated_existing']);
		self::assertSame(0, $stats['merged_placeholders']);
		self::assertSame(2, $stats['left_or_kicked']);
		self::assertSame(1, $stats['bots_skipped']);
		self::assertSame(0, $stats['errors']);

		$newbie = $this->app->players->findByTelegramId(3001);
		self::assertNotNull($newbie);
		self::assertSame('newbie', $newbie->username);
		self::assertSame('New Bie', $newbie->display_name);
		self::assertNull($this->app->players->findByTelegramId(3002));
		self::assertNull($this->app->players->findByTelegramId(3003));
	}

	public function testMergesPlaceholderByUsername(): void {
		$ph = $this->insertPlaceholder('zoloto');
		$importer = $this->importerWithMembers([
			9001 => [
				'status' => 'member',
				'user' => [
					'id' => 9001,
					'is_bot' => false,
					'username' => 'zoloto',
					'first_name' => 'Z',
				],
			],
		]);

		$stats = $importer->import(self::CHAT_ID, [9001], false);
		self::assertSame(1, $stats['merged_placeholders']);
		self::assertSame(0, $stats['imported_new']);
		$merged = $this->app->players->findByTelegramId(9001);
		self::assertNotNull($merged);
		self::assertSame($ph->id, $merged->id);
	}

	public function testDryRunDoesNotWrite(): void {
		$importer = $this->importerWithMembers([
			4001 => [
				'status' => 'member',
				'user' => [
					'id' => 4001,
					'is_bot' => false,
					'username' => 'ghost',
					'first_name' => 'G',
				],
			],
		]);

		$before = (int) $this->db->fetchValue('SELECT COUNT(*) FROM players');
		$stats = $importer->import(self::CHAT_ID, [4001], true);
		$after = (int) $this->db->fetchValue('SELECT COUNT(*) FROM players');

		self::assertTrue($stats['dry_run']);
		self::assertSame(1, $stats['imported_new']);
		self::assertSame($before, $after);
		self::assertNull($this->app->players->findByTelegramId(4001));
	}

	public function testRetriesOn429(): void {
		$calls = 0;
		$slept = [];
		$this->http->onTelegram('getChat', static fn (): array => [
			'ok' => true,
			'result' => ['id' => self::CHAT_ID, 'type' => 'supergroup', 'title' => 'Import chat'],
		]);
		$this->http->onTelegram('getChatMember', function () use (&$calls): array {
			$calls++;
			if ($calls === 1) {
				return [
					'ok' => false,
					'error_code' => 429,
					'description' => 'Too Many Requests: retry after 1',
					'parameters' => ['retry_after' => 1],
				];
			}
			return [
				'ok' => true,
				'result' => [
					'status' => 'member',
					'user' => [
						'id' => 5001,
						'is_bot' => false,
						'username' => 'retry_ok',
						'first_name' => 'R',
					],
				],
			];
		});
		$api = new BotApi($this->http, self::BOT_ID . ':TEST');
		$importer = new MembersImporter(
			$api,
			$this->app->players,
			new ChatRepository($this->db),
			$this->clock,
			static function (int $seconds) use (&$slept): void {
				$slept[] = $seconds;
			},
			1000,
		);

		$stats = $importer->import(self::CHAT_ID, [5001], false);
		self::assertSame([1], $slept);
		self::assertSame(1, $stats['imported_new']);
		self::assertSame(0, $stats['errors']);
		self::assertSame(2, $calls);
	}

	public function testIdempotentSecondRunUpdatesOnly(): void {
		$importer = $this->importerWithMembers([
			6001 => [
				'status' => 'member',
				'user' => [
					'id' => 6001,
					'is_bot' => false,
					'username' => 'stable',
					'first_name' => 'S',
				],
			],
		]);
		$first = $importer->import(self::CHAT_ID, [6001], false);
		self::assertSame(1, $first['imported_new']);
		$second = $importer->import(self::CHAT_ID, [6001], false);
		self::assertSame(0, $second['imported_new']);
		self::assertSame(1, $second['updated_existing']);
		$rows = $this->db->fetchAll('SELECT * FROM players WHERE tg_user_id = 6001');
		self::assertCount(1, $rows);
	}
}
