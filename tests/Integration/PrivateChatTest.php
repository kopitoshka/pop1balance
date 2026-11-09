<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Domain\CreateTournament;
use P1Bot\Domain\Model\Player;
use P1Bot\Support\Logger;
use P1Bot\Tests\Support\IntegrationTestCase;
use P1Bot\Tests\Support\LogCapture;
use P1Bot\View\Messages;

/**
	Private chat: any text is answered without a mention, read-only; changes go to the group.
*/
class PrivateChatTest extends IntegrationTestCase {
	protected const SECOND_CHAT = -1009876543210;

	protected ?LogCapture $log = null;

	protected function logger(): ?Logger {
		$this->log = new LogCapture();
		return $this->log->logger;
	}

	protected function config(): array {
		$config = parent::config();
		$config['openai']['api_key'] = 'sk-test';
		return $config;
	}

	/**
		Raw update with a private message from $from.
	*/
	protected function privateMessage(Player $from, string $text): array {
		$raw = $this->groupMessage($from, $text);
		$raw['message']['chat'] = [
			'id' => $this->tgIdOf($from),
			'type' => 'private',
			'first_name' => $from->display_name,
		];
		return $raw;
	}

	protected function say(Player $from, string $text): string {
		$this->http->reset();
		$this->dispatch($this->privateMessage($from, $text));
		$sent = $this->http->sent('sendMessage');
		self::assertNotSame([], $sent, 'the bot must answer in private: ' . $text);
		foreach ($sent as $message) {
			self::assertSame($this->tgIdOf($from), $message['chat_id']);
		}
		return implode("\n---\n", array_column($sent, 'text'));
	}

	public function testHelpWordsAndCommands(): void {
		$vasya = $this->player('vasya');
		foreach (['помощь', 'Справка!', '/help', '/start', 'help'] as $text) {
			$reply = $this->say($vasya, $text);
			self::assertStringContainsString('Что работает в личке', $reply, $text);
			self::assertStringNotContainsString('/level @', $reply);
			self::assertStringNotContainsStringIgnoringCase('шкал', $reply);
		}
		self::assertStringContainsString('private: help (/help)', $this->log->text());

		$owner_help = $this->say($this->owner, 'помощь');
		self::assertStringContainsString('Только для главного админа', $owner_help);
		self::assertStringContainsString('/level @user 4', $owner_help);
	}

	public function testLevels(): void {
		$vasya = $this->player('vasya');
		self::assertStringContainsString('Уровни игроков', $this->say($vasya, 'уровни'));
		self::assertStringContainsString('Уровни игроков', $this->say($vasya, '/levels'));
	}

	public function testTournamentsInOneGroup(): void {
		$vasya = $this->player('vasya');
		$this->openTournament(3);
		$this->app->tournaments->create(new CreateTournament(
			self::CHAT_ID,
			$this->owner->id,
			'Черновик владельца',
			'2026-11-20 19:00',
			3,
			'10',
		));

		$reply = $this->say($vasya, 'турниры');
		self::assertStringContainsString('Субботний кубок', $reply);
		self::assertStringNotContainsString('Черновик владельца', $reply, 'drafts are hidden from others');
		self::assertStringNotContainsString('Группа', $reply, 'one group: no group header');

		$owner_reply = $this->say($this->owner, '/tournaments');
		self::assertStringContainsString('Черновик владельца', $owner_reply);
		self::assertStringContainsString('его видишь только ты', $owner_reply);
	}

	public function testOrganizerSeesOwnDraftOnly(): void {
		$vasya = $this->player('vasya');
		$petya = $this->player('petya');
		// Vasya is the organizer of a draft (e.g. ANYONE_CAN_CREATE_TOURNAMENT=1).
		$this->app->tournament_repo->create([
			'chat_id' => self::CHAT_ID,
			'organizer_id' => $vasya->id,
			'title' => 'Кубок Васи',
			'starts_at' => \P1Bot\Domain\MoscowTime::parse('2026-11-20 19:00'),
			'team_size' => 3,
			'balance_id' => null,
			'reference_team' => null,
			'max_points' => 10,
			'max_players' => 24,
			'status' => \P1Bot\Domain\TournamentStatus::DRAFT,
		], $this->clock->now());

		self::assertStringContainsString('Кубок Васи', $this->say($vasya, 'турниры'));
		self::assertStringContainsString('Сейчас активных турниров нет', $this->say($petya, 'турниры'));
	}

	public function testSeveralGroupsShowGroupNamesAndTheirOwnNumbers(): void {
		$vasya = $this->player('vasya');
		$this->openTournament(3);
		$this->app->chats->ensure(self::SECOND_CHAT, 'Вторая группа');
		$t = $this->app->tournaments->create(new CreateTournament(
			self::SECOND_CHAT,
			$this->owner->id,
			'Кубок второй группы',
			'2026-11-21 19:00',
			2,
			'6',
		));
		$this->app->tournaments->openRegistration($this->owner->id, $t->id);

		$reply = $this->say($vasya, 'турниры');
		self::assertStringContainsString('👥 Группа <b>P1 турниры</b>', $reply);
		self::assertStringContainsString('👥 Группа <b>Вторая группа</b>', $reply);
		self::assertLessThan(strpos($reply, 'Вторая группа'), strpos($reply, 'Субботний кубок'));
		self::assertSame(2, substr_count($reply, '№1'), 'each group keeps its own numbering');
	}

	public function testChangesAreRedirectedToTheGroup(): void {
		$vasya = $this->player('vasya');
		$this->openTournament(3);
		foreach ([
			'/new 23.11 20:00 | Кубок | 3 | 10',
			'/open 1',
			'/edit 1 лимит 30',
			'/cancel 1',
			'/level @vasya 5',
			'/balance',
		] as $text) {
			self::assertSame(Messages::PRIVATE_REDIRECT, $this->say($vasya, $text), $text);
		}
		self::assertSame(24, $this->app->tournament_repo->find(1)->max_players);
		self::assertNull($this->app->tournament_repo->find(2));
		self::assertStringContainsString('private: redirect to group (/new)', $this->log->text());
		self::assertStringNotContainsString('ignored: private chat', $this->log->text());

		// A chat admin is redirected too: admin commands are done in the group.
		self::assertSame(Messages::PRIVATE_REDIRECT, $this->say($this->admin, '/close 1'));
	}

	public function testOwnerCommandsWorkInPrivate(): void {
		$vasya = $this->player('vasya');
		self::assertSame('Записал: @vasya — высокий (5).', $this->say($this->owner, '/level @vasya 5'));
		self::assertSame(5, $this->app->balances->levelOf($this->app->balances->findDefault()->id, $vasya->id));
		self::assertStringContainsString('@vasya — высокий (5)', $this->say($this->owner, '/balance'));
		self::assertStringContainsString('private: owner command (/level)', $this->log->text());
		self::assertSame(Messages::PRIVATE_REDIRECT, $this->say($this->owner, '/new 23.11 20:00 | Кубок | 3 | 10'));
	}

	public function testFreeTextGoesToReadOnlyAi(): void {
		$vasya = $this->player('vasya');
		$this->openTournament(3);
		$this->http->queueOpenAi(['id' => 'r1', 'output' => [[
			'type' => 'function_call',
			'id' => 'fc_1',
			'call_id' => 'c1',
			'name' => 'show_tournaments',
			'arguments' => '{}',
		]]]);
		$this->http->queueOpenAi(['id' => 'r2', 'output' => [[
			'type' => 'message',
			'role' => 'assistant',
			'content' => [['type' => 'output_text', 'text' => 'Вот что сейчас есть <3']],
		]]]);

		$reply = $this->say($vasya, 'когда ближайший турнир?');
		self::assertStringContainsString('Субботний кубок', $reply, 'the pretty list is sent');
		self::assertStringContainsString('Вот что сейчас есть &lt;3', $reply);

		$ai_requests = array_values(array_filter($this->http->requests, static function (array $r): bool {
			return str_ends_with($r['url'], '/responses');
		}));
		$first = $ai_requests[0]['json'];
		self::assertSame(
			['show_tournaments', 'show_levels'],
			array_column($first['tools'], 'name'),
			'read-only tools only',
		);
		self::assertStringContainsString('Субботний кубок', $first['input'][0]['content']);
		self::assertStringContainsString('Чего нельзя в личке', $first['instructions']);
		self::assertStringContainsString('private: ai', $this->log->text());
	}

	public function testUnknownToolIsRefused(): void {
		$vasya = $this->player('vasya');
		$this->openTournament(3);
		$this->http->queueOpenAi(['id' => 'r1', 'output' => [[
			'type' => 'function_call',
			'id' => 'fc_1',
			'call_id' => 'c1',
			'name' => 'join_solo',
			'arguments' => '{"tournament_id":1}',
		]]]);
		$this->http->queueOpenAi(['id' => 'r2', 'output' => [[
			'type' => 'message',
			'role' => 'assistant',
			'content' => [['type' => 'output_text', 'text' => Messages::PRIVATE_REDIRECT]],
		]]]);
		self::assertSame(Messages::PRIVATE_REDIRECT, $this->say($vasya, 'запиши меня соло'));
		self::assertSame(0, $this->app->tournaments->lineup(1)->occupied(), 'nothing changed');
	}

	public function testAiErrorGivesFriendlyText(): void {
		$vasya = $this->player('vasya');
		self::assertSame('Что-то пошло не так, попробуй ещё раз чуть позже.', $this->say($vasya, 'привет')); // queue empty: 500
	}
}
