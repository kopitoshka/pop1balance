<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Tests\Support\IntegrationTestCase;

class NickTest extends IntegrationTestCase {

	public function testSetOwnNickAndRejectTaken(): void {
		$vasya = $this->player('vasya');
		$petya = $this->player('petya');
		$this->dispatch($this->privateMessage($vasya, '/nick Zoloto on heat'));
		self::assertStringContainsString('Zoloto on heat', $this->http->lastText());
		self::assertSame('Zoloto on heat', $this->app->players->find($vasya->id)->ign);

		$this->dispatch($this->privateMessage($petya, '/nick zoloto on heat'));
		self::assertStringContainsString('уже занят', $this->http->lastText());
		self::assertNull($this->app->players->find($petya->id)->ign);

		$this->dispatch($this->privateMessage($vasya, '/nick'));
		self::assertStringContainsString('Твой игровой ник: <b>Zoloto on heat</b>', $this->http->lastText());

		$this->dispatch($this->privateMessage($vasya, '/nick Masta_Sega.Ekb'));
		self::assertSame('Masta_Sega.Ekb', $this->app->players->find($vasya->id)->ign);
	}

	public function testOwnerAssignTakesFromOther(): void {
		$vasya = $this->player('vasya');
		$petya = $this->player('petya');
		$this->app->nicks->setOwn($vasya->id, 'CAT_RRRRRRRAGE_RU');
		$this->dispatch($this->privateMessage($this->owner, '/nick @petya CAT_RRRRRRRAGE_RU'));
		$text = $this->http->lastText();
		self::assertStringContainsString('Поставила ник «CAT_RRRRRRRAGE_RU» игроку @petya', $text);
		self::assertStringContainsString('@vasya', $text);
		self::assertNull($this->app->players->find($vasya->id)->ign);
		self::assertSame('CAT_RRRRRRRAGE_RU', $this->app->players->find($petya->id)->ign);
	}

	public function testNonOwnerCannotAssign(): void {
		$vasya = $this->player('vasya');
		$petya = $this->player('petya');
		$this->dispatch($this->privateMessage($vasya, '/nick @petya SuperNick'));
		self::assertSame('Это доступно только главному админу.', $this->http->lastText());
	}

	public function testGroupNickRedirectsToPrivate(): void {
		$vasya = $this->player('vasya');
		$this->http->reset();
		$this->dispatch($this->groupMessage($vasya, '/nick CoolNick'));
		$sent = $this->http->sent('sendMessage');
		self::assertNotSame([], $sent);
		$url = $sent[0]['reply_markup']['inline_keyboard'][0][0]['url'] ?? null;
		self::assertNotNull($url);
		self::assertStringContainsString('start=', $url);
		self::assertNull($this->app->players->find($vasya->id)->ign);
	}

	public function testLineupShowsIgnAsLink(): void {
		$cap = $this->player('cap');
		$mate = $this->player('mate');
		$this->app->nicks->setOwn($cap->id, 'Zoloto on heat');
		$t = $this->openTournament(2, null);
		$this->app->teams->createTeam($t->id, $cap->id, 'Wolves', [$mate->id]);
		$this->dispatch($this->privateMessage($this->owner, '/info ' . $t->id));
		$text = $this->http->lastText();
		$cap_row = $this->app->players->find($cap->id);
		self::assertStringContainsString(
			'<a href="tg://user?id=' . $cap_row->tg_user_id . '">Zoloto on heat</a>',
			$text,
		);
		self::assertStringContainsString('@mate', $text);
		self::assertStringNotContainsString('@cap', $text);
	}

	public function testLevelAcceptsIgnReference(): void {
		$vasya = $this->player('vasya');
		$this->app->nicks->setOwn($vasya->id, 'Zoloto on heat');
		$this->dispatch($this->privateMessage($this->owner, '/level @vasya 4'));
		$this->http->reset();
		$this->dispatch($this->privateMessage($this->owner, '/level Zoloto on heat'));
		self::assertStringContainsString('Zoloto on heat (@vasya) — средний+ (4)', $this->http->lastText());
	}

	public function testHistoryKeepsPreviousNick(): void {
		$vasya = $this->player('vasya');
		$this->app->nicks->setOwn($vasya->id, 'OldNick');
		$this->app->nicks->setOwn($vasya->id, 'NewNick');
		$row = $this->app->db->fetchOne(
			'SELECT ign FROM player_ign_history WHERE player_id = :p ORDER BY id DESC LIMIT 1',
			['p' => $vasya->id],
		);
		self::assertSame('OldNick', $row['ign']);
	}

	public function testInvalidNickRejected(): void {
		$vasya = $this->player('vasya');
		$this->dispatch($this->privateMessage($vasya, '/nick a'));
		self::assertStringContainsString('от 2 до 32', $this->http->lastText());
		$this->dispatch($this->privateMessage($vasya, '/nick bad#nick'));
		self::assertStringContainsString('можно буквы', $this->http->lastText());
	}
}
