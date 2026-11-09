<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Domain\CreateTournament;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Handlers\Context;
use P1Bot\Telegram\Message;
use P1Bot\Tests\Support\IntegrationTestCase;

class DraftDeleteTest extends IntegrationTestCase {

	protected function makeDraft(string $title = 'Черновой кубок'): \P1Bot\Domain\Model\Tournament {
		return $this->app->tournaments->create(new CreateTournament(
			self::CHAT_ID,
			$this->owner->id,
			$title,
			'2026-11-14 19:00',
			2,
			'3,3',
		));
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

	public function testDraftCardHasDeleteButton(): void {
		$this->dispatch($this->privateMessage($this->owner, '/new 14.11 19:00 | Черновой кубок | 2 | 3,3'));
		$t = $this->app->tournament_repo->find(1);
		self::assertNotNull($t);
		self::assertContains('ddel:' . $t->id, $this->allCallbacks());
		self::assertContains('pub:' . $t->id, $this->allCallbacks());
	}

	public function testDeleteButtonConfirmsAndHardDeletes(): void {
		$this->dispatch($this->privateMessage($this->owner, '/new 14.11 19:00 | Черновой кубок | 2 | 3,3'));
		$t = $this->app->tournament_repo->find(1);
		self::assertNotNull($t);
		$this->http->reset();
		$this->dispatch($this->press($this->owner, 'ddel:' . $t->id));
		self::assertStringContainsString('Точно удалить черновик #' . $t->id, $this->http->lastText());
		self::assertContains('ddelo:' . $t->id, $this->allCallbacks());

		$this->http->reset();
		$this->dispatch($this->press($this->owner, 'ddelo:' . $t->id));
		self::assertStringContainsString('Черновик #' . $t->id . ' удалён', $this->http->lastText());
		self::assertNull($this->app->tournament_repo->find($t->id));
	}

	public function testCancelCommandDeletesDraftDirectly(): void {
		$t = $this->makeDraft();
		$this->dispatch($this->privateMessage($this->owner, '/cancel ' . $t->id));
		self::assertStringContainsString('Черновик #' . $t->id . ' удалён', $this->http->lastText());
		self::assertNull($this->app->tournament_repo->find($t->id));
	}

	public function testCancelPublishedStillCancels(): void {
		$t = $this->openTournament(2, null);
		$this->dispatch($this->privateMessage($this->owner, '/cancel ' . $t->id));
		self::assertStringContainsString('отменён', $this->http->lastText());
		self::assertSame(TournamentStatus::CANCELLED, $this->app->tournament_repo->find($t->id)->status);
	}

	public function testAiCancelDeletesDraft(): void {
		$t = $this->makeDraft();
		$raw = $this->privateMessage($this->owner, 'удали черновик');
		$ctx = new Context(
			Message::fromArray($raw['message']),
			$this->owner,
			true,
			true,
			self::CHAT_ID,
		);
		$result = $this->app->dispatcher->dispatch('cancel_tournament', [
			'tournament_id' => $t->id,
		], $ctx);
		self::assertTrue($result['ok']);
		self::assertTrue($result['deleted'] ?? false);
		self::assertStringContainsString('удалён', $result['message']);
		self::assertNull($this->app->tournament_repo->find($t->id));
	}

	public function testCronPurgesExpiredDrafts(): void {
		$past = $this->app->tournament_repo->create([
			'chat_id' => self::CHAT_ID,
			'organizer_id' => $this->owner->id,
			'title' => 'Просроченный',
			'starts_at' => $this->clock->now()->modify('-1 hour'),
			'team_size' => 2,
			'balance_id' => null,
			'reference_team' => null,
			'max_points' => null,
			'max_players' => 8,
			'status' => TournamentStatus::DRAFT,
		], $this->clock->now());
		$future = $this->makeDraft('Ещё живой');
		$stats = $this->app->cron()->run();
		self::assertSame(1, $stats['drafts_purged']);
		self::assertNull($this->app->tournament_repo->find($past->id));
		self::assertNotNull($this->app->tournament_repo->find($future->id));
	}

	public function testDeleteDraftRejectsPublished(): void {
		$t = $this->openTournament(2, null);
		$this->expectException(ValidationError::class);
		$this->app->tournaments->deleteDraft($this->owner->id, $t->id);
	}

	public function testStrangerCannotDeleteDraft(): void {
		$t = $this->makeDraft();
		$vasya = $this->player('nosy2');
		$this->dispatch($this->press($vasya, 'ddel:' . $t->id));
		$answers = $this->http->sent('answerCallbackQuery');
		self::assertStringContainsString('автор', (string) (end($answers)['text'] ?? ''));
		self::assertNotNull($this->app->tournament_repo->find($t->id));
	}
}
