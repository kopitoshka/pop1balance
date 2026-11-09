<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Support\Logger;
use P1Bot\Tests\Support\IntegrationTestCase;
use P1Bot\Tests\Support\LogCapture;
use P1Bot\View\Messages;

/**
	Group: info answers, solo join/leave, manage redirects, short AI chat.
*/
class GroupRedirectTest extends IntegrationTestCase {
	protected ?LogCapture $log = null;

	protected function logger(): ?Logger {
		$this->log = new LogCapture();
		return $this->log->logger;
	}

	protected function config(): array {
		return parent::config();
	}

	/**
		@param array<string, mixed> $extra
		@return array{text: string, url: ?string}
	*/
	protected function groupReply(\P1Bot\Domain\Model\Player $from, string $text, array $extra = []): array {
		$this->http->reset();
		$this->dispatch($this->groupMessage($from, $text, $extra));
		$sent = $this->http->sent('sendMessage');
		self::assertNotSame([], $sent, $text);
		$last = end($sent);
		$url = $last['reply_markup']['inline_keyboard'][0][0]['url'] ?? null;
		return ['text' => (string) $last['text'], 'url' => $url];
	}

	public function testInfoCommandsAreAnsweredInTheGroup(): void {
		$t = $this->openTournament(2, null);
		$this->app->teams->createTeam($t->id, $this->player('cap')->id, 'Wolves', [$this->player('mate')->id]);
		$vasya = $this->player('vasya');

		$this->dispatch($this->groupMessage($vasya, '/info'));
		self::assertStringContainsString('1. <b>Wolves</b> — 👑 @cap (кэп), @mate', $this->http->lastText());
		$this->dispatch($this->groupMessage($vasya, '/tournaments'));
		self::assertStringContainsString('Субботний кубок', $this->http->lastText());
		$this->dispatch($this->groupMessage($vasya, '/levels'));
		self::assertStringContainsString('Уровни игроков', $this->http->lastText());
		$this->dispatch($this->groupMessage($vasya, '/help'));
		$help = $this->http->lastText();
		self::assertStringContainsString(Messages::GROUP_HELP_HINT, $help);
		self::assertStringContainsString('/tournaments', $help);
		self::assertStringNotContainsString('/new', $help);
		$help_msg = $this->http->sent('sendMessage');
		$markup = end($help_msg)['reply_markup']['inline_keyboard'][0][0];
		self::assertSame(Messages::GROUP_REDIRECT_BUTTON, $markup['text']);
		self::assertSame('https://t.me/pop1_bot', $markup['url']);
		self::assertStringContainsString('group: /help', $this->log->text());
	}

	public function testNaturalInfoQuestions(): void {
		$this->openTournament(3);
		$vasya = $this->player('vasya');
		$reply = $this->groupReply($vasya, '@pop1_bot какие турниры?');
		self::assertStringContainsString('Субботний кубок', $reply['text']);
		$reply = $this->groupReply($vasya, '@pop1_bot что за уровни?');
		self::assertStringContainsString('Уровни игроков', $reply['text']);
		$reply = $this->groupReply($vasya, '@pop1_bot help');
		self::assertStringContainsString(Messages::GROUP_HELP_HINT, $reply['text']);
	}

	public function testGroupListHidesDrafts(): void {
		$this->openTournament(3);
		$this->app->tournaments->create(new \P1Bot\Domain\CreateTournament(
			self::CHAT_ID,
			$this->owner->id,
			'Черновик',
			'2026-11-20 19:00',
			3,
			'10',
		));
		$this->dispatch($this->groupMessage($this->owner, '/tournaments'));
		self::assertStringNotContainsString('Черновик', $this->http->lastText());
	}

	public function testSoloJoinAndLeaveInGroup(): void {
		$t = $this->openTournament(2, null, 2);
		$vasya = $this->player('vasya');
		$reply = $this->groupReply($vasya, '@pop1_bot запиши меня');
		self::assertStringContainsString('Записала тебя на «Субботний кубок» ✅ Ты в основе, 1/2.', $reply['text']);
		self::assertSame('main', $this->app->tournaments->lineup($t->id)->placementOf($vasya->id));

		$petya = $this->player('petya');
		$reply = $this->groupReply($petya, '@pop1_bot запиши меня на турнир 1');
		self::assertStringContainsString('Ты в основе, 2/2.', $reply['text']);

		$kolya = $this->player('kolya');
		$reply = $this->groupReply($kolya, '@pop1_bot запиши меня');
		self::assertStringContainsString('ты в резерве, №1', $reply['text']);

		$reply = $this->groupReply($vasya, '@pop1_bot удали меня');
		self::assertStringContainsString('Вычеркнула тебя из «Субботний кубок»', $reply['text']);
		self::assertNull($this->app->tournaments->lineup($t->id)->placementOf($vasya->id));
	}

	public function testAmbiguousJoinRedirects(): void {
		$this->openTournament(2, null);
		$t2 = $this->app->tournaments->create(new \P1Bot\Domain\CreateTournament(
			self::CHAT_ID,
			$this->owner->id,
			'Второй',
			'2026-11-15 19:00',
			2,
			'6',
		));
		$this->app->tournaments->openRegistration($this->owner->id, $t2->id);
		$reply = $this->groupReply($this->player('vasya'), '@pop1_bot запиши меня');
		self::assertSame(Messages::AMBIGUOUS_REDIRECTS[0], $reply['text']);
		self::assertNotNull($reply['url']);
		self::assertStringContainsString('start=g_', $reply['url']);
	}

	public function testManageCommandsRedirectWithRandomPhrase(): void {
		$this->openTournament(3);
		$reply = $this->groupReply($this->owner, '/new 23.11 20:00 | Кубок | 3 | 10');
		self::assertSame(Messages::MANAGE_REDIRECTS[0], $reply['text']);
		self::assertSame(Messages::GROUP_REDIRECT_BUTTON, '💬 Написать в личку');
		self::assertStringContainsString('start=g_', (string) $reply['url']);
		self::assertNull($this->app->tournament_repo->find(2));

		$reply = $this->groupReply($this->admin, '/edit 1 лимит 30');
		self::assertSame(Messages::MANAGE_REDIRECTS[0], $reply['text']);
		self::assertSame(24, $this->app->tournament_repo->find(1)->max_players);
		self::assertStringContainsString('start=t_1', (string) $reply['url']);

		$reply = $this->groupReply($this->player('vasya'), '@pop1_bot создай турнир на субботу');
		self::assertSame(Messages::MANAGE_REDIRECTS[0], $reply['text']);
	}

	public function testReplyToAnnouncementJoinsThatTournament(): void {
		$t = $this->openTournament(3);
		$announce_id = $this->app->announcer->publish($t->id);
		$vasya = $this->player('vasya');
		$reply = $this->groupReply($vasya, 'а можно соло?', ['reply_to_message' => [
			'message_id' => $announce_id,
			'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Bot'],
		]]);
		self::assertStringContainsString('Записала тебя на «Субботний кубок»', $reply['text']);
		self::assertSame('main', $this->app->tournaments->lineup($t->id)->placementOf($vasya->id));
	}

	public function testCaptainLeaveRedirects(): void {
		$t = $this->openTournament(2, null);
		$cap = $this->player('cap');
		$this->app->teams->createTeam($t->id, $cap->id, 'Wolves', [$this->player('mate')->id]);
		$reply = $this->groupReply($cap, '@pop1_bot удали меня');
		self::assertSame(Messages::CAPTAIN_LEAVE, $reply['text']);
		self::assertStringContainsString('start=t_' . $t->id, (string) $reply['url']);
		self::assertNotNull($this->app->teams->findByName($t->id, 'Wolves'));
	}

	public function testNonCaptainLeavesTeamInGroup(): void {
		$t = $this->openTournament(2, null);
		$cap = $this->player('cap');
		$mate = $this->player('mate');
		$this->app->teams->createTeam($t->id, $cap->id, 'Wolves', [$mate->id]);
		$reply = $this->groupReply($mate, '@pop1_bot выпиши меня');
		self::assertStringContainsString('Убрала тебя из команды «Wolves»', $reply['text']);
		self::assertNull($this->app->tournaments->lineup($t->id)->placementOf($mate->id));
	}

	public function testUnrecognizedShowsTournamentsAndHelpHint(): void {
		$this->openTournament(3);
		$vasya = $this->player('vasya');
		$reply = $this->groupReply($vasya, '@pop1_bot привет как дела');
		self::assertStringStartsWith(Messages::GROUP_FALLBACK_PREFIX, $reply['text']);
		self::assertStringContainsString('Субботний кубок', $reply['text']);
		self::assertStringEndsWith(Messages::GROUP_FALLBACK_HINT, $reply['text']);
		self::assertNull($reply['url']);
		self::assertSame([], array_filter($this->http->requests, static function (array $r): bool {
			return str_ends_with($r['url'], '/responses');
		}), 'no AI in the group');
	}

	public function testWhatsNewIsTournamentsList(): void {
		$this->openTournament(3);
		$reply = $this->groupReply($this->player('vasya'), '@pop1_bot Что нового?');
		self::assertStringContainsString('Субботний кубок', $reply['text']);
		self::assertStringContainsString('📋', $reply['text']);
		self::assertSame([], array_filter($this->http->requests, static function (array $r): bool {
			return str_ends_with($r['url'], '/responses');
		}));
	}

	public function testFallbackWhenNoTournaments(): void {
		$reply = $this->groupReply($this->player('vasya'), '@pop1_bot привет');
		self::assertSame(Messages::GROUP_FALLBACK_EMPTY, $reply['text']);
	}

	public function testUnaddressedMessagesAreIgnored(): void {
		$this->dispatch($this->groupMessage($this->player('vasya'), 'всем привет'));
		$this->dispatch($this->groupMessage($this->player('petya'), '/foo'));
		self::assertSame([], $this->http->sent('sendMessage'));
	}

	/**
		@param array<string, mixed> $body
		@return array<string, mixed>
	*/
}
