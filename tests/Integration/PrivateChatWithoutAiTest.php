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
	}

	public function testCardReplyWithoutAi(): void {
		$this->dispatch($this->privateMessage($this->owner, '/new 23.11 20:00 | Кубок | 2 | 3,4'));
		$t = $this->app->tournament_repo->find(1);
		$reply = ['reply_to_message' => [
			'message_id' => $t->card_msg_id,
			'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Bot'],
		]];
		$this->dispatch($this->privateMessage($this->owner, 'а время?', $reply));
		self::assertStringContainsString('Не публикую, пока ты явно не подтвердишь', $this->http->lastText());
		$this->dispatch($this->privateMessage($this->owner, 'да', $reply));
		self::assertSame('registration', $this->app->tournament_repo->find(1)->status);
	}
}
