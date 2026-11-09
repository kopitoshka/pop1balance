<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Support\Logger;
use P1Bot\Tests\Support\IntegrationTestCase;
use P1Bot\Tests\Support\LogCapture;
use P1Bot\View\Messages;

/**
	The group only gets announcements and info commands; everything else is «Давай в личке 👉».
*/
class GroupRedirectTest extends IntegrationTestCase {
	protected ?LogCapture $log = null;

	protected function logger(): ?Logger {
		$this->log = new LogCapture();
		return $this->log->logger;
	}

	protected function config(): array {
		$config = parent::config();
		$config['openai']['api_key'] = 'sk-test'; // AI is on, but never used in the group
		return $config;
	}

	/**
		@param array<string, mixed> $extra
		@return string the deep link of the single redirect reply
	*/
	protected function redirected(\P1Bot\Domain\Model\Player $from, string $text, array $extra = []): string {
		$this->http->reset();
		$this->dispatch($this->groupMessage($from, $text, $extra));
		$sent = $this->http->sent('sendMessage');
		self::assertCount(1, $sent, $text);
		self::assertSame(self::CHAT_ID, $sent[0]['chat_id']);
		self::assertSame(Messages::GROUP_REDIRECT, $sent[0]['text'], $text);
		$button = $sent[0]['reply_markup']['inline_keyboard'][0][0];
		self::assertSame(Messages::GROUP_REDIRECT_BUTTON, $button['text']);
		self::assertSame([], array_filter($this->http->requests, static function (array $r): bool {
			return str_ends_with($r['url'], '/responses');
		}), 'no AI in the group');
		return $button['url'];
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
		self::assertStringContainsString('пишите мне в личку', $help);
		self::assertStringContainsString('@pop1_bot', $help);
		self::assertStringContainsString('/tournaments', $help);
		self::assertStringContainsString('/info', $help);
		self::assertStringContainsString('/levels', $help);
		self::assertStringNotContainsString('/new', $help);
		self::assertStringNotContainsString('/group', $help);
		self::assertStringNotContainsString('/level @', $help);
		$help_msg = $this->http->sent('sendMessage');
		$markup = end($help_msg)['reply_markup']['inline_keyboard'][0][0];
		self::assertSame(Messages::GROUP_REDIRECT_BUTTON, $markup['text']);
		self::assertSame('https://t.me/pop1_bot', $markup['url']);
		foreach ($this->http->sent('sendMessage') as $message) {
			self::assertSame(self::CHAT_ID, $message['chat_id']);
		}
		self::assertStringContainsString('group: info /help', $this->log->text());
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

	public function testMentionsAndActionsAreRedirected(): void {
		$vasya = $this->player('vasya');
		$this->openTournament(3);
		$group_link = 'https://t.me/pop1_bot?start=g_' . self::CHAT_ID;
		self::assertSame($group_link, $this->redirected($vasya, '@pop1_bot запиши меня соло'));
		self::assertStringContainsString('group: redirect (mention) start=g_' . self::CHAT_ID, $this->log->text());

		// Admin commands too: nothing changes in the group.
		self::assertSame('https://t.me/pop1_bot?start=t_1', $this->redirected($this->admin, '/edit 1 лимит 30'));
		self::assertSame(24, $this->app->tournament_repo->find(1)->max_players);
		self::assertSame($group_link, $this->redirected($this->owner, '/new 23.11 20:00 | Кубок | 3 | 10'));
		self::assertNull($this->app->tournament_repo->find(2));
		self::assertStringContainsString('group: redirect (/new)', $this->log->text());

		// A number of an existing tournament in the text opens it; an unknown one names the group.
		self::assertSame('https://t.me/pop1_bot?start=t_1', $this->redirected($vasya, '@pop1_bot что с турниром 1?'));
		self::assertSame($group_link, $this->redirected($vasya, '@pop1_bot что с №7?'));
		self::assertSame($group_link, $this->redirected($vasya, '@pop1_bot создай на 23.11 20:00'));
	}

	public function testReplyToAnnouncementOpensThatTournament(): void {
		$t = $this->openTournament(3);
		$announce_id = $this->app->announcer->publish($t->id);
		$url = $this->redirected($this->player('vasya'), 'а можно соло?', ['reply_to_message' => [
			'message_id' => $announce_id,
			'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Bot'],
		]]);
		self::assertSame('https://t.me/pop1_bot?start=t_' . $t->id, $url);
		self::assertStringContainsString('group: redirect (reply) start=t_' . $t->id, $this->log->text());
	}

	public function testUnaddressedMessagesAreIgnored(): void {
		$this->dispatch($this->groupMessage($this->player('vasya'), 'всем привет'));
		$this->dispatch($this->groupMessage($this->player('petya'), '/foo'));
		self::assertSame([], $this->http->sent('sendMessage'));
	}
}
