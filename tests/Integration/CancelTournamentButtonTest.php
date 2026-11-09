<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\App\PrivateViews;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Tests\Support\IntegrationTestCase;

class CancelTournamentButtonTest extends IntegrationTestCase {

	protected function views(): PrivateViews {
		return new PrivateViews(
			$this->app->telegram,
			$this->app->tournaments,
			$this->app->teams,
			$this->app->announcer,
			$this->app->listing,
			$this->app->private_groups,
			$this->app->access,
			$this->app->team_cards,
		);
	}

	/**
		@return list<string>
	*/
	protected function callbacksFrom(array $rows): array {
		$out = [];
		foreach ($rows as $row) {
			foreach ($row as $btn) {
				if (isset($btn['callback_data'])) {
					$out[] = $btn['callback_data'];
				}
			}
		}
		return $out;
	}

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

	public function testCancelButtonOnlyForAuthorOrOwner(): void {
		$t = $this->openTournament(2, null);
		$views = $this->views();
		$vasya = $this->player('vasya');
		self::assertContains('tcancel:' . $t->id, $this->callbacksFrom($views->rows($t, $this->owner)));
		self::assertNotContains('tcancel:' . $t->id, $this->callbacksFrom($views->rows($t, $vasya)));
		// Group admin who is not organizer/owner must not see cancel.
		self::assertNotContains('tcancel:' . $t->id, $this->callbacksFrom($views->rows($t, $this->admin)));
	}

	public function testCancelHiddenWhenFinishedOrCancelled(): void {
		$t = $this->openTournament(2, null);
		$this->app->tournaments->cancel($this->owner->id, $t->id);
		$t = $this->app->tournaments->get($t->id);
		self::assertSame([], $this->views()->rows($t, $this->owner));
	}

	public function testCancelButtonConfirmsThenCancelsPublished(): void {
		$t = $this->openTournament(2, null);
		$this->dispatch($this->press($this->owner, 'popen:' . $t->id));
		self::assertContains('tcancel:' . $t->id, $this->allCallbacks());

		$this->http->reset();
		$this->dispatch($this->press($this->owner, 'tcancel:' . $t->id));
		self::assertStringContainsString('Точно отменить турнир', $this->http->lastText());
		self::assertContains('tcancely:' . $t->id, $this->allCallbacks());
		self::assertContains('tcanceln:' . $t->id, $this->allCallbacks());
		self::assertStringContainsString('Да, отменить', json_encode($this->http->sent('sendMessage'), JSON_UNESCAPED_UNICODE));

		$this->http->reset();
		$this->dispatch($this->press($this->owner, 'tcancely:' . $t->id));
		self::assertStringContainsString('отменён', $this->http->lastText());
		self::assertSame(TournamentStatus::CANCELLED, $this->app->tournament_repo->find($t->id)->status);
	}

	public function testCancelNoAborts(): void {
		$t = $this->openTournament(2, null);
		$this->dispatch($this->press($this->owner, 'tcancel:' . $t->id));
		$this->http->reset();
		$this->dispatch($this->press($this->owner, 'tcanceln:' . $t->id));
		self::assertSame(TournamentStatus::REGISTRATION, $this->app->tournament_repo->find($t->id)->status);
	}

	public function testCancelClearsPendingText(): void {
		$t = $this->openTournament(2, null);
		$cap = $this->player('cap');
		$this->rate($cap, 3);
		$entry = $this->app->teams->createTeamAsOrganizer($this->owner->id, $t->id, $cap->id, 'Wolves', []);
		$this->dispatch($this->press($this->owner, 'otren:' . $entry->team->id));
		self::assertSame('org_rename', $this->app->organizer_teams->hasPending($this->owner->id, self::OWNER_TG_ID));

		$this->http->reset();
		$this->dispatch($this->press($this->owner, 'tcancel:' . $t->id));
		self::assertNull(
			$this->app->organizer_teams->hasPending($this->owner->id, self::OWNER_TG_ID),
			'pressing cancel tournament must clear pending text input',
		);
	}

	public function testNonManagerCannotConfirmCancel(): void {
		$t = $this->openTournament(2, null);
		$vasya = $this->player('vasya');
		$this->dispatch($this->press($vasya, 'tcancely:' . $t->id));
		self::assertSame(TournamentStatus::REGISTRATION, $this->app->tournament_repo->find($t->id)->status);
	}
}
