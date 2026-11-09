<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Domain\CreateTournament;
use P1Bot\Repository\GroupNoticeRepository;
use P1Bot\Support\Logger;
use P1Bot\Tests\Support\IntegrationTestCase;
use P1Bot\Tests\Support\LogCapture;

/**
	REMIND_ALL_GROUPS: an hour before the start every group the bot is in hears about the tournament.
*/
class GroupNoticeTest extends IntegrationTestCase {
	protected const SECOND = -100222;
	protected const THIRD = -100333;
	protected const GONE = -100444;

	protected bool $remind_all = true;

	protected ?LogCapture $log = null;

	protected function logger(): ?Logger {
		$this->log = new LogCapture();
		return $this->log->logger;
	}

	protected function config(): array {
		$config = parent::config();
		$config['cron']['remind_all_groups'] = $this->remind_all;
		return $config;
	}

	protected function setUp(): void {
		parent::setUp();
		$this->app->chats->ensure(self::SECOND, 'Вторая группа');
		$this->app->chats->ensure(self::THIRD, 'Третья группа');
		$this->app->chats->ensure(self::GONE, 'Ушли');
		$this->app->chats->deactivate(self::GONE);
		$this->app->chats->ensure(4242, 'Личка'); // private chats have rows too
	}

	/**
		@return array<int, list<array<string, mixed>>> chat_id => sent messages
	*/
	protected function sentByChat(): array {
		$result = [];
		foreach ($this->http->sent('sendMessage') as $message) {
			$result[$message['chat_id']][] = $message;
		}
		return $result;
	}

	protected function toHourBefore(): void {
		$this->clock->advance('+5 days +3 hours +1 minute'); // 15:01 UTC = 59 min before 19:00 MSK
	}

	public function testAllGroupsGetOneMessageAnHourBefore(): void {
		$t = $this->openTournament(2, null);
		$this->app->announcer->publish($t->id);
		$this->app->teams->joinSolo($t->id, $this->player('solo')->id);
		$cron = $this->app->cron();
		$this->http->reset();

		$this->toHourBefore();
		self::assertSame(1, $cron->run()['reminders']);
		$sent = $this->sentByChat();
		self::assertSame([self::CHAT_ID, self::SECOND, self::THIRD], array_keys($sent));
		self::assertCount(1, $sent[self::CHAT_ID], 'own group: just the usual reminder');
		self::assertStringContainsString('Через 60 минут старт турнира', $sent[self::CHAT_ID][0]['text']);
		foreach ([self::SECOND, self::THIRD] as $chat_id) {
			self::assertCount(1, $sent[$chat_id]);
			$notice = $sent[$chat_id][0];
			self::assertSame(
				"⏰ Через час стартует турнир <b>Субботний кубок</b>!\n"
					. "🗓 14.11.2026 ⏰ 19:00 МСК\n"
					. "👥 Команды по 2 игрока\n"
					. "⚖️ Уровень: без ограничения по уровню\n"
					. "🎟 Занято 1/24\n"
					. "\n"
					. 'Ещё можно успеть! Записаться соло или командой — у меня в личке 👇',
				$notice['text'],
			);
			self::assertSame(
				[['text' => '💬 Записаться / вопросы в личке', 'url' => 'https://t.me/pop1_bot?start=t_' . $t->id]],
				$notice['reply_markup']['inline_keyboard'][0],
			);
		}
		self::assertStringContainsString('sent to 2 other group(s)', $this->log->text());

		// Reruns and the 15-minute reminder: nothing new for other groups.
		$this->http->reset();
		self::assertSame(0, $cron->run()['reminders']);
		$this->clock->advance('+45 minutes');
		self::assertSame(1, $cron->run()['reminders']);
		self::assertSame([self::CHAT_ID], array_keys($this->sentByChat()));
		self::assertStringContainsString('Через 15 минут', $this->http->lastText());
	}

	public function testOverlappingRunsDoNotDuplicate(): void {
		$t = $this->openTournament(2, null);
		$this->toHourBefore();
		$this->app->cron()->run();
		// Another cron run that read the tournament before reminded_at was saved.
		$this->app->tournament_repo->update($t->id, ['reminded_at' => null]);
		$this->http->reset();
		$this->app->cron()->run();
		self::assertSame([], $this->http->sent('sendMessage'));
		self::assertSame(
			[self::CHAT_ID => 'sent', self::THIRD => 'sent', self::SECOND => 'sent'],
			(new GroupNoticeRepository($this->db))->listForTournament($t->id),
		);
	}

	public function testDisabled(): void {
		$this->remind_all = false;
		$this->setUp(); // rebuild the app with the flag off
		$this->openTournament(2, null);
		$this->toHourBefore();
		$this->app->cron()->run();
		self::assertSame([self::CHAT_ID], array_keys($this->sentByChat()));
	}

	public function testKickedGroupIsDeactivatedAndOthersStillGetIt(): void {
		$this->http->onTelegram('sendMessage', static function (array $json): array {
			if ($json['chat_id'] === self::SECOND) {
				return ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was kicked from the supergroup chat'];
			}
			if ($json['chat_id'] === self::THIRD) {
				return ['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 5'];
			}
			return ['ok' => true, 'result' => ['message_id' => 1]];
		});
		$this->app->chats->ensure(-100555, 'Пятая группа');
		$t = $this->openTournament(2, null);
		$this->toHourBefore();
		self::assertSame(1, $this->app->cron()->run()['reminders']);

		self::assertFalse($this->app->chats->isActive(self::SECOND), 'kicked: inactive');
		self::assertTrue($this->app->chats->isActive(self::THIRD), 'temporary error: still active');
		$results = (new GroupNoticeRepository($this->db))->listForTournament($t->id);
		self::assertSame('sent', $results[-100555]);
		self::assertStringContainsString('error: ', $results[self::THIRD]);
		self::assertStringContainsString('marked inactive', $this->log->text());
		self::assertStringContainsString('sent to 1 other group(s)', $this->log->text());
	}

	public function testOnlyPublishedTournaments(): void {
		$t = $this->openTournament(2, null);
		$this->app->tournaments->cancel($this->owner->id, $t->id);
		$this->app->tournaments->create(new CreateTournament(
			self::CHAT_ID,
			$this->owner->id,
			'Черновик',
			'2026-11-14 19:00',
			2,
			'3,3',
		));
		$this->http->reset();
		$this->toHourBefore();
		self::assertSame(0, $this->app->cron()->run()['reminders']);
		self::assertSame([], $this->http->sent('sendMessage'));
	}

	public function testClosedRegistrationText(): void {
		$t = $this->openTournament(2, null);
		$this->app->tournaments->closeRegistration($this->owner->id, $t->id);
		$this->toHourBefore();
		$this->app->cron()->run();
		self::assertStringContainsString(
			'Регистрация уже закрыта, но вопросы можно задать мне в личке 👇',
			$this->sentByChat()[self::SECOND][0]['text'],
		);
	}

	public function testBotRemovalAndRestrictionAreTracked(): void {
		$this->dispatch(['update_id' => 1, 'my_chat_member' => [
			'chat' => ['id' => self::SECOND, 'type' => 'supergroup', 'title' => 'Вторая группа'],
			'old_chat_member' => ['status' => 'member'],
			'new_chat_member' => ['status' => 'restricted', 'is_member' => true],
		]]);
		self::assertTrue($this->app->chats->isActive(self::SECOND), 'restricted but still a member');
		$this->dispatch(['update_id' => 2, 'my_chat_member' => [
			'chat' => ['id' => self::SECOND, 'type' => 'supergroup', 'title' => 'Вторая группа'],
			'old_chat_member' => ['status' => 'restricted', 'is_member' => true],
			'new_chat_member' => ['status' => 'left'],
		]]);
		self::assertFalse($this->app->chats->isActive(self::SECOND));
		self::assertArrayNotHasKey(self::SECOND, $this->app->chats->listActiveGroups());
	}
}
