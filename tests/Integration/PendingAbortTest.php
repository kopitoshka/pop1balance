<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\App\PendingTextGuard;
use P1Bot\Tests\Support\IntegrationTestCase;

class PendingAbortTest extends IntegrationTestCase {

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

	public function testRenamePromptHasCancel(): void {
		$t = $this->openTournament(2, null);
		$cap = $this->player('rcap');
		$this->rate($cap, 3);
		$entry = $this->app->teams->createTeamAsOrganizer($this->owner->id, $t->id, $cap->id, 'OldName', []);
		$this->dispatch($this->press($this->owner, 'otren:' . $entry->team->id));
		self::assertContains(PendingTextGuard::CANCEL_DATA, $this->allCallbacks());
		self::assertStringContainsString('Новое название', $this->http->lastText());
	}

	public function testForeignButtonAbortsRenameThenTextIgnored(): void {
		$t = $this->openTournament(2, null);
		$cap = $this->player('rcap2');
		$this->rate($cap, 3);
		$entry = $this->app->teams->createTeamAsOrganizer($this->owner->id, $t->id, $cap->id, 'Wolves', []);
		$this->dispatch($this->press($this->owner, 'otren:' . $entry->team->id));
		self::assertTrue($this->app->organizer_teams->hasPending($this->owner->id, self::OWNER_TG_ID) === 'org_rename');

		// Other button: open tournament card.
		$this->http->reset();
		$this->dispatch($this->press($this->owner, 'popen:' . $t->id));
		self::assertNull($this->app->organizer_teams->hasPending($this->owner->id, self::OWNER_TG_ID));

		$this->http->reset();
		$this->dispatch($this->privateMessage($this->owner, 'NewWolves'));
		$entry = $this->app->teams->get($entry->team->id);
		self::assertSame('Wolves', $entry->team->name, 'rename must not apply after abort');
	}

	public function testCancelClearsPendingAndConfirms(): void {
		$t = $this->openTournament(2, null);
		$cap = $this->player('rcap3');
		$this->rate($cap, 3);
		$entry = $this->app->teams->createTeamAsOrganizer($this->owner->id, $t->id, $cap->id, 'Foxes', []);
		$this->dispatch($this->press($this->owner, 'otren:' . $entry->team->id));
		$this->http->reset();
		$this->dispatch($this->press($this->owner, PendingTextGuard::CANCEL_DATA));
		self::assertNull($this->app->organizer_teams->hasPending($this->owner->id, self::OWNER_TG_ID));
		$texts = array_column($this->http->sent('sendMessage'), 'text');
		self::assertTrue(
			(bool) array_filter($texts, static fn ($x) => str_contains((string) $x, 'Отменила')),
			'expected cancel confirmation',
		);
		$this->dispatch($this->privateMessage($this->owner, 'ShouldNotRename'));
		self::assertSame('Foxes', $this->app->teams->get($entry->team->id)->team->name);
	}

	public function testCommandClearsPending(): void {
		$t = $this->openTournament(2, null);
		$cap = $this->player('rcap4');
		$this->rate($cap, 3);
		$entry = $this->app->teams->createTeamAsOrganizer($this->owner->id, $t->id, $cap->id, 'Bears', []);
		$this->dispatch($this->press($this->owner, 'otren:' . $entry->team->id));
		self::assertSame('org_rename', $this->app->organizer_teams->hasPending($this->owner->id, self::OWNER_TG_ID));
		$this->dispatch($this->privateMessage($this->owner, '/help'));
		self::assertNull($this->app->organizer_teams->hasPending($this->owner->id, self::OWNER_TG_ID));
		$this->dispatch($this->privateMessage($this->owner, 'NotARename'));
		self::assertSame('Bears', $this->app->teams->get($entry->team->id)->team->name);
	}

	public function testStaleCancelAnswersGracefully(): void {
		$this->dispatch($this->press($this->owner, PendingTextGuard::CANCEL_DATA));
		$answers = $this->http->sent('answerCallbackQuery');
		self::assertStringContainsString('нечего отменять', (string) (end($answers)['text'] ?? ''));
	}

	public function testTeamAddCandidateStillWorksWithPending(): void {
		$t = $this->openTournament(3);
		$cap = $this->player('addcap');
		$friend = $this->player('addfriend');
		$this->rate($cap, 3);
		$this->rate($friend, 3);
		$entry = $this->app->teams->createTeam($t->id, $cap->id, 'Alpha', []);
		$past = $this->openTournament(2, '3,3');
		$this->app->teams->createTeam($past->id, $cap->id, 'Old', [$friend->id]);
		$this->dispatch($this->press($cap, 'padd:' . $entry->team->id));
		self::assertTrue($this->app->team_add->hasPending($cap->id, $this->tgIdOf($cap)));
		self::assertContains(PendingTextGuard::CANCEL_DATA, $this->allCallbacks());
		$this->http->reset();
		$this->dispatch($this->press($cap, 'tadd:' . $entry->team->id . ':' . $friend->id));
		self::assertSame(2, $this->app->teams->get($entry->team->id)->size());
		self::assertFalse($this->app->team_add->hasPending($cap->id, $this->tgIdOf($cap)));
	}
}
