<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Repository\ConversationRepository;
use P1Bot\Tests\Support\IntegrationTestCase;

class PlayerRepositoryTest extends IntegrationTestCase {

	public function testUsernamePlaceholderIsClaimedByRealAccount(): void {
		$placeholder = $this->app->players->ensureByUsername('@Vasya', $this->clock->now());
		self::assertNull($placeholder->tg_user_id);
		self::assertSame('vasya', $placeholder->username);

		$real = $this->app->players->ensureByTelegram(777, 'vasya', 'Вася', $this->clock->now());
		self::assertSame($placeholder->id, $real->id);
		self::assertSame(777, $real->tg_user_id);
		self::assertSame('Вася', $real->display_name);
	}

	public function testRatingsMergeWhenUsernameClaimedByExistingTgUser(): void {
		$real = $this->app->players->ensureTelegramId(9001, 'NoNick', $this->clock->now());
		$placeholder = $this->app->players->ensureByUsername('zoloto', $this->clock->now());
		self::assertNotSame($real->id, $placeholder->id);
		$this->rate($placeholder, 4);

		$merged = $this->app->players->ensureByTelegram(9001, 'zoloto', 'Zoloto', $this->clock->now());
		self::assertSame($real->id, $merged->id);
		self::assertSame(4, $this->app->balances->levelOf($this->app->balances->viewScaleId(), $real->id));
		self::assertNull($this->app->players->find($placeholder->id)->username);
		self::assertSame(
			[],
			$this->db->fetchAll('SELECT * FROM balance_ratings WHERE player_id = :p', ['p' => $placeholder->id]),
		);
	}

	public function testUsernameChangeUpdatesRow(): void {
		$p = $this->app->players->ensureByTelegram(778, 'old', 'Old', $this->clock->now());
		$p2 = $this->app->players->ensureByTelegram(778, 'new', 'New', $this->clock->now());
		self::assertSame($p->id, $p2->id);
		self::assertSame('@new', $p2->mention());
		self::assertNull($this->app->players->findByUsername('old'));
	}

	public function testConversationMemory(): void {
		$repo = new ConversationRepository($this->db);
		$id = $repo->save(
			null,
			self::CHAT_ID,
			$this->owner->id,
			55,
			[['role' => 'user', 'content' => 'привет']],
			$this->clock->now()->modify('+10 minutes'),
		);
		$found = $repo->findByBotMessage(self::CHAT_ID, 55, $this->clock->now());
		self::assertSame($id, $found['id']);
		self::assertSame('привет', $found['history'][0]['content']);

		$this->clock->advance('+11 minutes');
		self::assertNull($repo->findByBotMessage(self::CHAT_ID, 55, $this->clock->now()));
		self::assertSame(1, $repo->deleteExpired($this->clock->now()));
	}
}
