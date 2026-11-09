<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Domain\Model\Player;
use P1Bot\Tests\Support\IntegrationTestCase;

/**
	Per-user dialog logs in storage/logs/dialogs.
*/
class DialogLogTest extends IntegrationTestCase {
	protected string $logs = '';

	protected bool $dialog_log = true;

	protected function setUp(): void {
		$this->logs = sys_get_temp_dir() . '/p1bot_dialogs_' . getmypid() . '_' . mt_rand();
		parent::setUp();
	}

	protected function tearDown(): void {
		foreach (glob($this->logs . '/dialogs/*') ?: [] as $file) {
			unlink($file);
		}
		@rmdir($this->logs . '/dialogs');
		@rmdir($this->logs);
		parent::tearDown();
	}

	protected function config(): array {
		$config = parent::config();
		$config['paths'] = ['logs' => $this->logs];
		$config['dialog_log'] = $this->dialog_log;
		return $config;
	}

	protected function dialog(string $file): string {
		$path = $this->logs . '/dialogs/' . $file;
		return is_file($path) ? (string) file_get_contents($path) : '';
	}

	public function testPrivateDialogIsPlainTextWithIndentedLines(): void {
		$this->openTournament(3);
		$vasya = $this->player('vasya');
		$this->dispatch($this->privateMessage($vasya, '/start'));

		$log = $this->dialog('vasya.log');
		self::assertStringStartsWith("[2026-11-09 15:00:00 MSK] личка @vasya: /start\n", $log);
		self::assertStringContainsString('[2026-11-09 15:00:00 MSK] личка бот → @vasya: Привет, Vasya! 👋', $log);
		self::assertStringContainsString(
			"\n\t🏆 Субботний кубок · №1",
			$log,
			'continuation lines are indented, HTML stripped',
		);
		self::assertStringNotContainsString('<b>', $log);
		foreach (explode("\n", trim($log)) as $line) {
			self::assertMatchesRegularExpression('/^(\[\d{4}-\d\d-\d\d \d\d:\d\d:\d\d MSK\] |\t|$)/u', $line);
		}
	}

	public function testGroupMentionAndInfoCommand(): void {
		$vasya = $this->player('vasya');
		$this->dispatch($this->groupMessage($vasya, '@pop1_bot запиши меня'));
		$this->dispatch($this->groupMessage($vasya, '/levels'));
		$this->dispatch($this->groupMessage($vasya, 'просто болтаю в группе'));

		$log = $this->dialog('vasya.log');
		self::assertStringContainsString('группа «P1 турниры» @vasya: @pop1_bot запиши меня', $log);
		self::assertStringContainsString('группа «P1 турниры» бот → @vasya: Давай в личке 👉', $log);
		self::assertStringContainsString('группа «P1 турниры» @vasya: /levels', $log);
		self::assertStringContainsString('группа «P1 турниры» бот → @vasya: 📊 Уровни игроков', $log);
		self::assertStringNotContainsString('просто болтаю', $log, 'messages not for the bot are not logged');
	}

	public function testButtonsAndPopupsAreLogged(): void {
		$t = $this->openTournament(2, null);
		$this->app->announcer->publish($t->id);
		$vasya = $this->player('vasya');
		$raw = $this->press($vasya, 'psolo:' . $t->id);
		$raw['callback_query']['message']['reply_markup'] = ['inline_keyboard' => [
			[['text' => '🙋 Записаться соло', 'callback_data' => 'psolo:' . $t->id]],
		]];
		$this->dispatch($raw);
		$this->dispatch($this->press($vasya, 'popen:' . $t->id));

		$log = $this->dialog('vasya.log');
		self::assertStringContainsString('личка @vasya: [кнопка] 🙋 Записаться соло', $log);
		self::assertStringContainsString('личка бот → @vasya: (всплывающее) Записал тебя соло!', $log);
		self::assertStringContainsString(
			'личка @vasya: [кнопка] popen:' . $t->id,
			$log,
			'data when the label is unknown',
		);
		self::assertStringNotContainsString(
			'Записаться командой или соло',
			$log,
			'the group announcement edit is not logged',
		);
	}

	public function testPublishingLogsThePrivateReplyButNotTheAnnouncement(): void {
		$this->dispatch($this->privateMessage($this->owner, '/new 23.11 20:00 | Кубок | 2 | 3,4'));
		$card_id = $this->app->tournament_repo->find(1)->card_msg_id;
		$this->dispatch($this->privateMessage($this->owner, 'да', ['reply_to_message' => [
			'message_id' => $card_id,
			'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Bot'],
		]]));
		$log = $this->dialog('andrey.log');
		self::assertStringContainsString('Публикуем или что-то поправить?', $log);
		self::assertStringContainsString('личка бот → @andrey: (исправил сообщение) 📝 Турнир №1', $log);
		self::assertStringContainsString('Опубликовал! Анонс уже в группе P1 турниры', $log);
		self::assertStringNotContainsString('Записаться командой или соло', $log, 'the announcement is a broadcast');
	}

	public function testFileNamesAndEditedMessages(): void {
		$odd = $this->app->players->ensureByTelegram(4242, 'Vasya_PRO', 'Вася', $this->clock->now());
		$this->dispatch($this->privateMessage($odd, '/help'));
		self::assertStringContainsString('личка @vasya_pro: /help', $this->dialog('vasya_pro.log'));

		$anon = new Player(0, 5151, null, 'Аноним');
		$this->dispatch($this->privateMessage($anon, '/help'));
		self::assertStringContainsString('личка Аноним (id5151): /help', $this->dialog('id5151.log'));

		$raw = $this->privateMessage($odd, 'исправил текст');
		$raw['edited_message'] = $raw['message'];
		unset($raw['message']);
		$this->dispatch($raw);
		$log = $this->dialog('vasya_pro.log');
		self::assertStringContainsString('личка @vasya_pro: [исправлено] исправил текст', $log);
		self::assertStringContainsString('личка бот → @vasya_pro: Исправленные сообщения я не вижу', $log);
	}

	public function testCronBroadcastsAreNotLogged(): void {
		$t = $this->openTournament(3);
		$this->app->announcer->publish($t->id);
		$this->app->announcer->refresh($t->id);
		self::assertSame([], glob($this->logs . '/dialogs/*') ?: []);
	}

	public function testDisabled(): void {
		$this->dialog_log = false;
		parent::setUp();
		$this->dispatch($this->privateMessage($this->player('vasya'), '/start'));
		self::assertDirectoryDoesNotExist($this->logs . '/dialogs');
	}
}
