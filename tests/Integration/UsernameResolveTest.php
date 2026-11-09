<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\App\PlayerResolver;
use P1Bot\App\UsernameResolver;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Tests\Support\FakeUsernameLookup;
use P1Bot\Tests\Support\IntegrationTestCase;

class UsernameResolveTest extends IntegrationTestCase {

	public function testMtprotoUpsertsUnknownUsername(): void {
		$lookup = new FakeUsernameLookup([
			'ghost' => [
				'id' => 90001,
				'first_name' => 'Ghost',
				'last_name' => 'User',
				'username' => 'ghost',
			],
		]);
		$resolver = new PlayerResolver($this->app->players, $this->clock, 'pop1_bot', $lookup);
		$player = $resolver->fromReference('@ghost', $this->owner);
		self::assertSame(90001, $player->tg_user_id);
		self::assertSame('ghost', $player->username);
		self::assertSame('Ghost User', $player->display_name);
		self::assertSame($player->id, $this->app->players->findKnownByUsername('ghost')->id);
	}

	public function testMtprotoMergesPlaceholder(): void {
		$ph = $this->insertPlaceholder('newbie');
		$lookup = new FakeUsernameLookup([
			'newbie' => [
				'id' => 90002,
				'first_name' => 'New',
				'last_name' => '',
				'username' => 'newbie',
			],
		]);
		$resolver = new PlayerResolver($this->app->players, $this->clock, 'pop1_bot', $lookup);
		$player = $resolver->fromReference('@newbie', $this->owner);
		self::assertSame($ph->id, $player->id);
		self::assertSame(90002, $player->tg_user_id);
	}

	public function testUnavailableLookupKeepsOldMessage(): void {
		$lookup = new FakeUsernameLookup([], false);
		$resolver = new PlayerResolver($this->app->players, $this->clock, 'pop1_bot', $lookup);
		try {
			$resolver->fromReference('@ghost', $this->owner);
			self::fail('expected ValidationError');
		} catch (ValidationError $e) {
			self::assertStringContainsString('@ghost ещё не знаком со мной', $e->getMessage());
		}
		self::assertNull($this->app->players->findByUsername('ghost'));
	}

	public function testNegativeCacheSkipsSecondCall(): void {
		$dir = sys_get_temp_dir() . '/p1_ur_' . getmypid();
		@mkdir($dir, 0700, true);
		$calls = 0;
		$runner = function (string $username) use (&$calls): array {
			$calls++;
			$body = json_encode([
				'username' => $username,
				'ok' => false,
				'error' => 'not_found',
			], JSON_UNESCAPED_UNICODE);
			return [1, $body . "\n", ''];
		};
		$resolver = new UsernameResolver([
			'api_id' => 1,
			'api_hash' => 'hash',
			'token' => '1:TEST',
			'python' => 'python3.11',
			'script' => __DIR__ . '/../../bin/resolve_username.py',
			'session_dir' => $dir,
			'timeout' => 5,
			'negative_ttl' => 600,
		], $this->clock, null, $runner);

		self::assertTrue($resolver->isAvailable());
		self::assertNull($resolver->resolve('missingnick'));
		self::assertNull($resolver->resolve('missingnick'));
		self::assertSame(1, $calls);

		foreach (glob($dir . '/*') ?: [] as $f) {
			@unlink($f);
		}
		@rmdir($dir);
	}

	public function testFloodWaitRemembered(): void {
		$dir = sys_get_temp_dir() . '/p1_ur_f_' . getmypid();
		@mkdir($dir, 0700, true);
		$calls = 0;
		$runner = function (string $username) use (&$calls): array {
			$calls++;
			$body = json_encode([
				'username' => $username,
				'ok' => false,
				'error' => 'flood_wait',
				'retry_after' => 120,
			], JSON_UNESCAPED_UNICODE);
			return [3, $body . "\n", ''];
		};
		$resolver = new UsernameResolver([
			'api_id' => 1,
			'api_hash' => 'hash',
			'token' => '1:TEST',
			'script' => __DIR__ . '/../../bin/resolve_username.py',
			'session_dir' => $dir,
		], $this->clock, null, $runner);

		self::assertNull($resolver->resolve('anyone'));
		self::assertNull($resolver->resolve('anyone'));
		self::assertSame(1, $calls);
		self::assertFileExists($dir . '/username_resolve_flood_until');

		foreach (glob($dir . '/*') ?: [] as $f) {
			@unlink($f);
		}
		@rmdir($dir);
	}

	public function testSuccessfulResolveViaRunner(): void {
		$dir = sys_get_temp_dir() . '/p1_ur_ok_' . getmypid();
		@mkdir($dir, 0700, true);
		$runner = static function (string $username): array {
			$body = json_encode([
				'username' => $username,
				'ok' => true,
				'id' => 555,
				'first_name' => 'A',
				'last_name' => 'B',
				'username_actual' => $username,
			], JSON_UNESCAPED_UNICODE);
			return [0, $body . "\n", ''];
		};
		$resolver = new UsernameResolver([
			'api_id' => 1,
			'api_hash' => 'hash',
			'token' => '1:TEST',
			'script' => __DIR__ . '/../../bin/resolve_username.py',
			'session_dir' => $dir,
		], $this->clock, null, $runner);
		$info = $resolver->resolve('SomeNick');
		self::assertNotNull($info);
		self::assertSame(555, $info['id']);
		self::assertSame('SomeNick', $info['username']);

		foreach (glob($dir . '/*') ?: [] as $f) {
			@unlink($f);
		}
		@rmdir($dir);
	}
}
