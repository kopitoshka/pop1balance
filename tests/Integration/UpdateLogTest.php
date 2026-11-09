<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Support\Logger;
use P1Bot\Tests\Support\IntegrationTestCase;
use P1Bot\Tests\Support\LogCapture;

/**
	One info line per update with the decision, so silence on a mention can be explained from the log.
*/
class UpdateLogTest extends IntegrationTestCase {
	protected ?LogCapture $log = null;

	protected function logger(): ?Logger {
		$this->log = new LogCapture();
		return $this->log->logger;
	}

	protected function config(): array {
		$config = parent::config();
		$config['openai']['api_key'] = 'sk-test-0123456789abcdef';
		return $config;
	}

	protected function lastUpdateLine(): string {
		$lines = array_values(array_filter(
			$this->log->lines(),
			static fn (string $l): bool => str_contains($l, ' INFO update '),
		));
		self::assertNotSame([], $lines, 'no update line logged');
		return end($lines);
	}

	public function testNoMention(): void {
		$vasya = $this->player('vasya');
		$this->dispatch($this->groupMessage($vasya, 'всем привет, кто играет сегодня?'));

		$line = $this->lastUpdateLine();
		self::assertMatchesRegularExpression(
			'/INFO update \d+ message: ignored: no mention\/reply \(expected @pop1_bot\)/',
			$line,
		);
		self::assertStringContainsString('"chat":' . self::CHAT_ID, $line);
		self::assertStringContainsString('"chat_type":"supergroup"', $line);
		self::assertStringContainsString('"username":"@vasya"', $line);
		self::assertStringContainsString('"text":"всем привет, кто играет сегодня?"', $line);
		self::assertStringContainsString('"bot":"@pop1_bot"', $line);
		self::assertSame([], $this->http->sent('sendMessage'));
	}

	public function testWrongBotUsernameInMention(): void {
		$vasya = $this->player('vasya');
		$this->dispatch($this->groupMessage($vasya, '@pop1balance_bot привет'));

		self::assertStringContainsString(
			'ignored: wrong bot username in mention (@pop1balance_bot, expected @pop1_bot from TELEGRAM_BOT_USERNAME)',
			$this->lastUpdateLine(),
		);
	}

	public function testPrivateChat(): void {
		$vasya = $this->player('vasya');
		$update = $this->groupMessage($vasya, '@pop1_bot привет');
		$update['message']['chat'] = ['id' => 4242, 'type' => 'private'];
		$this->dispatch($update);

		$line = $this->lastUpdateLine();
		self::assertStringContainsString('private: ai', $line);
		self::assertStringContainsString('"chat_type":"private"', $line);
	}

	public function testCommandAndAi(): void {
		$vasya = $this->player('vasya');
		$this->dispatch($this->groupMessage($vasya, '/tournaments'));
		self::assertStringContainsString('handled: command /tournaments', $this->lastUpdateLine());

		$this->http->queueOpenAi(['id' => 'r1', 'output' => [[
			'type' => 'message',
			'role' => 'assistant',
			'content' => [['type' => 'output_text', 'text' => 'Привет!']],
		]]]);
		$this->dispatch($this->groupMessage($vasya, '@pop1_bot привет'));
		$text = $this->log->text();
		self::assertStringContainsString('handled: ai', $text);
		self::assertStringContainsString('INFO ai request start: model=', $text);
		self::assertStringContainsString('INFO ai response HTTP 200', $text);
		self::assertStringNotContainsString('0123456789abcdef', $text);
	}

	public function testLongTextIsCut(): void {
		$vasya = $this->player('vasya');
		$this->dispatch($this->groupMessage($vasya, str_repeat('а', 150)));
		self::assertStringContainsString('"text":"' . str_repeat('а', 100) . '…"', $this->lastUpdateLine());
	}

	public function testEditedMentionRepliedOnceThenIgnored(): void {
		$vasya = $this->player('vasya');
		$update = $this->groupMessage($vasya, '@pop1_bot привет');
		$update['edited_message'] = $update['message'] + ['edit_date' => 1];
		unset($update['message']);

		$this->dispatch($update);
		self::assertMatchesRegularExpression(
			'/update \d+ edited_message: edited: replied once/',
			$this->lastUpdateLine(),
		);
		$this->dispatch($update);
		self::assertStringContainsString('ignored: edited, already replied', $this->lastUpdateLine());
	}

	public function testSendMessageFailureIsLogged(): void {
		$this->http->onTelegram(
			'sendMessage',
			static fn (): array => ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was kicked'],
		);
		$vasya = $this->player('vasya');
		try {
			$this->dispatch($this->groupMessage($vasya, '/tournaments'));
		} catch (\Throwable $e) {
		}
		self::assertStringContainsString(
			'ERROR telegram sendMessage ok=false: 403 Forbidden: bot was kicked',
			$this->log->text(),
		);
	}
}
