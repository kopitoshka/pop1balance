<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Tests\Support\IntegrationTestCase;

/**
	Private chat with OPENAI_API_KEY empty: commands, buttons and keywords work, free text gets a hint.
*/
class PrivateChatWithoutAiTest extends IntegrationTestCase {

	public function testFreeTextGetsHintAndKeywordsWork(): void {
		$vasya = $this->player('vasya');
		$this->dispatch($this->privateMessage($vasya, 'привет, что тут есть?'));
		self::assertStringContainsString('Я сейчас понимаю только команды и кнопки', $this->http->lastText());
		self::assertSame([], array_filter($this->http->requests, static function (array $r): bool {
			return str_ends_with($r['url'], '/responses');
		}));

		$this->dispatch($this->privateMessage($vasya, 'турниры'));
		self::assertStringContainsString('Сейчас активных турниров нет', $this->http->lastText());
		$this->dispatch($this->privateMessage($vasya, 'уровни'));
		self::assertStringContainsString('Уровни игроков', $this->http->lastText());

		$this->dispatch($this->privateMessage($vasya, '/help'));
		$slash = $this->http->lastText();
		$this->dispatch($this->privateMessage($vasya, 'help'));
		self::assertSame($slash, $this->http->lastText());
		self::assertStringContainsString('кнопками на карточке турнира', $this->http->lastText());
		self::assertStringNotContainsString('обычным языком', $this->http->lastText());
		$this->dispatch($this->privateMessage($vasya, 'что ты умеешь?'));
		self::assertSame($slash, $this->http->lastText());
	}

}
