<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Tests\Support\IntegrationTestCase;

class OrganizerTeamsTest extends IntegrationTestCase {

	/**
		@return list<string>
	*/
	protected function allCallbacks(): array {
		$out = [];
		foreach ($this->http->sent('sendMessage') as $msg) {
			foreach ($msg['reply_markup']['inline_keyboard'] ?? [] as $row) {
				foreach ($row as $btn) {
					if (isset($btn['callback_data'])) {
						$out[] = $btn['callback_data'];
					}
				}
			}
		}
		return $out;
	}

	public function testTournamentCardShowsTeamsButtonForOrganizer(): void {
		$t = $this->openTournament(3);
		$this->dispatch($this->press($this->owner, 'popen:' . $t->id));
		self::assertContains('oteams:' . $t->id, $this->allCallbacks());
		$vasya = $this->player('vasya');
		$this->http->reset();
		$this->dispatch($this->press($vasya, 'popen:' . $t->id));
		self::assertNotContains('oteams:' . $t->id, $this->allCallbacks());
	}

	public function testListCreateRenameCaptainDelete(): void {
		$t = $this->openTournament(3);
		$cap = $this->player('cap');
		$mate = $this->player('mate');
		$other = $this->player('other');
		$this->rate($cap, 3);
		$this->rate($mate, 3);
		$this->rate($other, 3);

		$this->dispatch($this->press($this->owner, 'oteams:' . $t->id));
		self::assertStringContainsString('Команды', $this->http->lastText());
		self::assertContains('otnew:' . $t->id, $this->allCallbacks());

		$this->http->reset();
		$this->dispatch($this->press($this->owner, 'otnew:' . $t->id));
		self::assertStringContainsString('Как назовём', $this->http->lastText());

		$this->http->reset();
		$this->dispatch($this->privateMessage($this->owner, 'Wolves'));
		$texts = array_column($this->http->sent('sendMessage'), 'text');
		self::assertTrue(
			(bool) array_filter($texts, static fn ($x) => str_contains((string) $x, 'Кто капитан')),
			'expected captain prompt',
		);

		$this->http->reset();
		$this->dispatch($this->privateMessage($this->owner, 'cap'));
		self::assertContains('otcc:' . $t->id . ':' . $cap->id, $this->allCallbacks());

		$this->http->reset();
		$this->dispatch($this->press($this->owner, 'otcc:' . $t->id . ':' . $cap->id));
		$entry = $this->app->teams->findByName($t->id, 'Wolves');
		self::assertNotNull($entry);
		self::assertSame($cap->id, $entry->team->captain_id);
		self::assertNull($this->app->teams->teamOf($t->id, $this->owner->id), 'organizer not auto-added');
		self::assertStringContainsString('Wolves', $this->http->lastText());

		$this->app->teams->addPlayer($this->owner->id, $entry->team->id, $mate->id);
		$entry = $this->app->teams->get($entry->team->id);

		$this->http->reset();
		$this->dispatch($this->press($this->owner, 'otren:' . $entry->team->id));
		$this->dispatch($this->privateMessage($this->owner, 'Foxes'));
		$entry = $this->app->teams->get($entry->team->id);
		self::assertSame('Foxes', $entry->team->name);

		$this->http->reset();
		$this->dispatch($this->press($this->owner, 'otcap:' . $entry->team->id));
		self::assertContains('otsetc:' . $entry->team->id . ':' . $mate->id, $this->allCallbacks());
		$this->dispatch($this->press($this->owner, 'otsetc:' . $entry->team->id . ':' . $mate->id));
		$entry = $this->app->teams->get($entry->team->id);
		self::assertSame($mate->id, $entry->team->captain_id);

		$this->http->reset();
		$this->dispatch($this->press($this->owner, 'otdel:' . $entry->team->id));
		self::assertContains('otdelo:' . $entry->team->id, $this->allCallbacks());
		$this->dispatch($this->press($this->owner, 'otdelo:' . $entry->team->id));
		self::assertNull($this->app->teams->findByName($t->id, 'Foxes'));
		$texts = array_column($this->http->sent('sendMessage'), 'text');
		self::assertTrue(
			(bool) array_filter($texts, static fn ($x) => str_contains((string) $x, 'удалена')),
			'expected delete confirmation',
		);
		unset($other);
	}

	public function testCreateTeamAsOrganizerViaDomain(): void {
		$t = $this->openTournament(2, null);
		$cap = $this->player('solo_cap');
		$this->rate($cap, 3);
		$entry = $this->app->teams->createTeamAsOrganizer(
			$this->owner->id,
			$t->id,
			$cap->id,
			'OrgTeam',
			[],
		);
		self::assertSame($cap->id, $entry->team->captain_id);
		self::assertSame(1, $entry->size());
		self::assertNull($this->app->teams->teamOf($t->id, $this->owner->id));
	}

	public function testAiRegisterTeamWithCaptain(): void {
		$t = $this->openTournament(2, null);
		$cap = $this->player('aicaps');
		$this->rate($cap, 3);
		$raw = $this->privateMessage($this->owner, 'создай команду');
		$msg = \P1Bot\Telegram\Message::fromArray($raw['message']);
		$ctx = new \P1Bot\Handlers\Context(
			$message = $msg,
			$actor = $this->owner,
			$is_admin = true,
			$is_owner = true,
			$group_id = self::CHAT_ID,
		);
		$result = $this->app->dispatcher->dispatch('register_team', [
			'tournament_id' => $t->id,
			'team_name' => 'AI Wolves',
			'captain' => '@aicaps',
			'players' => [],
		], $ctx);
		self::assertTrue($result['ok']);
		$entry = $this->app->teams->findByName($t->id, 'AI Wolves');
		self::assertNotNull($entry);
		self::assertSame($cap->id, $entry->team->captain_id);
	}

	public function testStrangerCannotOpenTeamsList(): void {
		$t = $this->openTournament(2, null);
		$vasya = $this->player('nosy');
		$this->dispatch($this->press($vasya, 'oteams:' . $t->id));
		self::assertStringContainsString('организатор', $this->lastAnswer());
	}

	protected function lastAnswer(): string {
		$answers = $this->http->sent('answerCallbackQuery');
		self::assertNotSame([], $answers);
		return (string) (end($answers)['text'] ?? '');
	}
}
