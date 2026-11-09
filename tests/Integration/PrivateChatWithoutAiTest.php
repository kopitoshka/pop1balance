<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Tests\Support\IntegrationTestCase;

/**
	Private chat with OPENAI_API_KEY empty: keywords still work, free text gets a hint.
*/
class PrivateChatWithoutAiTest extends IntegrationTestCase {

	public function testFreeTextGetsHintAndKeywordsWork(): void {
		$vasya = $this->player('vasya');
		$raw = $this->groupMessage($vasya, 'привет, что тут есть?');
		$raw['message']['chat'] = ['id' => $this->tgIdOf($vasya), 'type' => 'private'];
		$this->dispatch($raw);
		self::assertStringContainsString('Здесь я пока понимаю только простые запросы', $this->http->lastText());
		self::assertSame([], array_filter($this->http->requests, static function (array $r): bool {
			return str_ends_with($r['url'], '/responses');
		}));

		$raw = $this->groupMessage($vasya, 'турниры');
		$raw['message']['chat'] = ['id' => $this->tgIdOf($vasya), 'type' => 'private'];
		$this->dispatch($raw);
		self::assertStringContainsString('Сейчас активных турниров нет', $this->http->lastText());
	}
}
