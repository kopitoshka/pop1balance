<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Telegram\Update;
use P1Bot\Tests\Support\IntegrationTestCase;

class InlinePlayersTest extends IntegrationTestCase {

	public function testAllowedUpdatesIncludeInlineQuery(): void {
		self::assertContains('inline_query', Update::ALLOWED_UPDATES);
	}

	public function testSearchPrefixThenContains(): void {
		$this->app->players->ensureByTelegram(501, 'dima_pro', 'Dima', $this->clock->now());
		$this->app->players->ensureByTelegram(502, 'vadim', 'Vadim', $this->clock->now());
		$this->app->players->ensureByTelegram(503, 'other', 'Someone', $this->clock->now());
		$this->insertPlaceholder('dimghost'); // no tg — must be skipped

		$found = $this->app->players->searchKnown('dim', 20);
		$names = array_map(static fn ($p) => $p->username, $found);
		self::assertSame(['dima_pro', 'vadim'], $names);
	}

	public function testInlineQueryAnswersArticles(): void {
		$p = $this->app->players->ensureByTelegram(601, 'dimon', 'Димон', $this->clock->now());
		$this->rate($p, 4);
		$this->app->nicks->assignByOwner($this->owner->id, $p->id, 'DimON');

		$this->dispatch([
			'update_id' => 77,
			'inline_query' => [
				'id' => 'iq1',
				'from' => ['id' => self::OWNER_TG_ID, 'is_bot' => false, 'first_name' => 'A', 'username' => 'andrey'],
				'query' => 'dim',
				'offset' => '',
			],
		]);
		$sent = $this->http->sent('answerInlineQuery');
		self::assertCount(1, $sent);
		self::assertSame('iq1', $sent[0]['inline_query_id']);
		self::assertSame(10, $sent[0]['cache_time']);
		self::assertTrue($sent[0]['is_personal']);
		$results = $sent[0]['results'];
		self::assertNotEmpty($results);
		self::assertSame('article', $results[0]['type']);
		self::assertSame('@dimon', $results[0]['title']);
		self::assertSame('@dimon', $results[0]['input_message_content']['message_text']);
		self::assertStringContainsString('DimON', $results[0]['description']);
		self::assertStringContainsString('средний+', $results[0]['description']);
	}

	public function testPlayerWithoutUsernameInsertsName(): void {
		$this->app->players->ensureByTelegram(602, null, 'Без Ника', $this->clock->now());
		$found = $this->app->players->searchKnown('Без', 10);
		self::assertCount(1, $found);
		$this->dispatch([
			'update_id' => 78,
			'inline_query' => [
				'id' => 'iq2',
				'from' => ['id' => 1, 'is_bot' => false, 'first_name' => 'X'],
				'query' => 'Без',
				'offset' => '',
			],
		]);
		$results = $this->http->sent('answerInlineQuery')[0]['results'];
		self::assertSame('Без Ника', $results[0]['input_message_content']['message_text']);
	}
}
