<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Tests\Support\IntegrationTestCase;

class TeamAddTest extends IntegrationTestCase {

	public function testTeamCommandShowsCardWithAddButton(): void {
		$cap = $this->player('cap');
		$mate = $this->player('mate');
		$t = $this->openTournament(3);
		$entry = $this->app->teams->createTeam($t->id, $cap->id, 'Wolves', [$mate->id]);

		$this->dispatch($this->privateMessage($cap, '/team'));
		$text = $this->http->lastText();
		self::assertStringContainsString('Wolves', $text);
		self::assertStringContainsString('Субботний кубок', $text);
		$buttons = [];
		foreach ($this->http->sent('sendMessage') as $msg) {
			foreach ($msg['reply_markup']['inline_keyboard'] ?? [] as $row) {
				foreach ($row as $btn) {
					$buttons[] = $btn['callback_data'] ?? '';
				}
			}
		}
		self::assertContains('padd:' . $entry->team->id, $buttons);
	}

	public function testTeamCommandEmptyHint(): void {
		$vasya = $this->player('lonely');
		$this->dispatch($this->privateMessage($vasya, '/team'));
		self::assertStringContainsString('не в команде', $this->http->lastText());
	}

	public function testPaddOffersCandidatesAndSearch(): void {
		$cap = $this->player('cap2');
		$friend = $this->player('dimitry');
		$outsider = $this->player('zzz');
		unset($outsider);
		$t = $this->openTournament(3);
		$entry = $this->app->teams->createTeam($t->id, $cap->id, 'Alpha', []);
		// Shared history: friend played with cap in another tournament.
		$past = $this->openTournament(2, '3,4');
		$this->app->teams->createTeam($past->id, $cap->id, 'Old', [$friend->id]);

		$this->dispatch($this->press($cap, 'padd:' . $entry->team->id));
		$texts = array_column($this->http->sent('sendMessage'), 'text');
		self::assertTrue(
			(bool) array_filter($texts, static fn ($t) => str_contains((string) $t, 'Кого добавить')),
			'expected offer text',
		);
		$inline = [];
		foreach ($this->http->sent('sendMessage') as $msg) {
			foreach ($msg['reply_markup']['inline_keyboard'] ?? [] as $row) {
				foreach ($row as $btn) {
					$inline[] = $btn['callback_data'] ?? '';
				}
			}
		}
		self::assertContains('tadd:' . $entry->team->id . ':' . $friend->id, $inline);

		$this->http->reset();
		$this->dispatch($this->privateMessage($cap, 'dim'));
		$found = false;
		foreach ($this->http->sent('sendMessage') as $msg) {
			foreach ($msg['reply_markup']['inline_keyboard'] ?? [] as $row) {
				foreach ($row as $btn) {
					if (($btn['callback_data'] ?? '') === 'tadd:' . $entry->team->id . ':' . $friend->id) {
						$found = true;
					}
				}
			}
		}
		self::assertTrue($found, 'search should offer dimitry');
	}

	public function testTaddAddsPlayerAndRefreshesCard(): void {
		$cap = $this->player('cap3');
		$newbie = $this->player('newbie');
		$t = $this->openTournament(3);
		$entry = $this->app->teams->createTeam($t->id, $cap->id, 'Beta', []);
		$this->dispatch($this->press($cap, 'tadd:' . $entry->team->id . ':' . $newbie->id));
		self::assertNotNull($this->app->teams->teamOf($t->id, $newbie->id));
		self::assertSame(2, $this->app->teams->get($entry->team->id)->size());
	}

	public function testGroupTeamRedirects(): void {
		$vasya = $this->player('grp_team');
		$this->dispatch($this->groupMessage($vasya, '/team'));
		self::assertStringContainsString('личк', mb_strtolower($this->http->lastText()));
	}
}
