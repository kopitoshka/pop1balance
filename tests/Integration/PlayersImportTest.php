<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Domain\AccessPolicy;
use P1Bot\Domain\PlayersImport;
use P1Bot\Domain\Model\Player;
use P1Bot\Tests\Support\IntegrationTestCase;

class PlayersImportTest extends IntegrationTestCase {

	protected function say(Player $who, string $text): string {
		$this->http->reset();
		$this->dispatch($this->privateMessage($who, $text));
		return $this->http->lastText();
	}

	protected function allTexts(): string {
		return implode("\n", array_column($this->http->sent('sendMessage'), 'text'));
	}

	protected function scaleId(): int {
		$scale = $this->app->balances->findDefault();
		self::assertNotNull($scale);
		return $scale->id;
	}

	public function testAddsNewPlayersAndUpdatesExistingWithSeparateSections(): void {
		$vasya = $this->player('vasya');
		$this->app->nicks->setOwn($vasya->id, 'Vasya');
		$this->app->balances->setLevel(self::OWNER_TG_ID, $this->owner->id, $vasya->id, 3);
		$petya = $this->player('petya');
		$this->app->nicks->setOwn($petya->id, 'Petya');
		$this->app->balances->setLevel(self::OWNER_TG_ID, $this->owner->id, $petya->id, 5);
		$kolya = $this->player('kolya');
		$this->app->nicks->setOwn($kolya->id, 'Kolya');
		$this->app->balances->setLevel(self::OWNER_TG_ID, $this->owner->id, $kolya->id, 2);

		$list = implode("\n", [
			'Zoloto on heat 5',
			'@new_guy',
			'Vasya',
			'Petya 5',
			'Petya 4',
			'Kolya 2',
			'Stas 66',
			'',
			'Тихий Охотник',
		]);
		$reply = $this->say($this->owner, "/players_import\n" . $list);
		self::assertStringContainsString('<b>Добавила</b> (3):', $reply);
		self::assertStringContainsString('Zoloto on heat — Танк (5)', $reply);
		self::assertStringContainsString('<a href="https://t.me/new_guy">@new_guy</a> — БТР (3)', $reply);
		self::assertStringContainsString('Тихий Охотник — БТР (3)', $reply);
		self::assertStringContainsString('<b>Обновила</b> (1):', $reply);
		self::assertStringContainsString('Petya (@petya)</a> — Танк (5) → Вертолёт (4)', $reply);
		self::assertStringContainsString('Пропустила 2 строки (игрок уже есть и уровень не менялся).', $reply);
		self::assertStringContainsString('• «Stas 66» — уровень должен быть от 1 до 6, а указан 66.', $reply);
		self::assertNull($this->app->players->findByIgnNorm('stas'), 'an error line changes nothing');

		$scale = $this->scaleId();
		$zoloto = $this->app->players->findByIgnNorm('zoloto on heat');
		self::assertSame([null, null, 'Zoloto on heat', 5], [
			$zoloto->tg_user_id,
			$zoloto->username,
			$zoloto->display_name,
			$this->app->balances->levelOf($scale, $zoloto->id),
		]);
		$guy = $this->app->players->findByUsername('new_guy');
		self::assertSame([null, 'new_guy', null, 3], [
			$guy->tg_user_id,
			$guy->username,
			$guy->ign,
			$this->app->balances->levelOf($scale, $guy->id),
		]);
		self::assertSame(3, $this->app->balances->levelOf($scale, $vasya->id), 'existing without a level on the line is skipped');
		self::assertSame(4, $this->app->balances->levelOf($scale, $petya->id));
	}

	public function testLastDuplicateLineWinsAndTheCapRefusesTheWholeList(): void {
		$this->say($this->owner, "/players_import\nMisha 2\nMisha 6");
		$misha = $this->app->players->findByIgnNorm('misha');
		self::assertSame(6, $this->app->balances->levelOf($this->scaleId(), $misha->id));

		$before = (int) $this->db->fetchValue('SELECT COUNT(*) FROM players');
		$lines = [];
		for ($i = 1; $i <= PlayersImport::MAX_LINES + 1; $i++) {
			$lines[] = 'CapPlayer' . $i;
		}
		$reply = $this->say($this->owner, "/players_import\n" . implode("\n", $lines));
		self::assertSame(
			sprintf(PlayersImport::TOO_MANY, PlayersImport::MAX_LINES, PlayersImport::MAX_LINES + 1),
			$reply,
		);
		self::assertSame($before, (int) $this->db->fetchValue('SELECT COUNT(*) FROM players'));
		self::assertNull($this->app->players->findByIgnNorm('capplayer1'));
	}

	public function testOwnerPrivateOnlyAndGroupRedirects(): void {
		$plain = $this->player('plain');
		$this->withoutSharedOwnerGroup($plain);
		self::assertSame(AccessPolicy::OWNER_ONLY, $this->say($plain, "/players_import\nЧужак 4"));
		self::assertNull($this->app->players->findByIgnNorm('чужак'));

		$creator = $this->player('creator');
		self::assertSame(AccessPolicy::OWNER_ONLY, $this->say($creator, "/players_import\nТожеЧужак"));
		self::assertNull($this->app->players->findByIgnNorm('тожечужак'));

		$this->http->reset();
		$this->dispatch($this->groupMessage($this->owner, "/players_import\nГрупповой 4"));
		self::assertStringContainsString('личк', $this->http->lastText());
		self::assertNull($this->app->players->findByIgnNorm('групповой'));
	}

	public function testBareCommandWaitsForTheNextMessageAndCancelClearsIt(): void {
		$this->http->reset();
		$this->dispatch($this->privateMessage($this->owner, '/players_import'));
		$sent = $this->http->sent('sendMessage');
		$last = end($sent);
		self::assertSame(PlayersImport::PROMPT, $last['text']);
		self::assertSame(PlayersImport::CANCEL_BUTTON, $last['reply_markup']['inline_keyboard'][0][0]['text']);
		self::assertSame('pcancel', $last['reply_markup']['inline_keyboard'][0][0]['callback_data']);

		$reply = $this->say($this->owner, "Ждун 4");
		self::assertStringContainsString('<b>Добавила</b> (1):', $reply);
		self::assertStringContainsString('Ждун — Вертолёт (4)', $reply);
		self::assertNotNull($this->app->players->findByIgnNorm('ждун'));
	}

	public function testWaitingCancelAndOtherCommand(): void {
		$this->dispatch($this->privateMessage($this->owner, '/players_import'));
		$this->http->reset();
		$this->dispatch($this->press($this->owner, 'pcancel'));
		self::assertStringContainsString('Отменила', $this->http->lastText());
		$before = (int) $this->db->fetchValue('SELECT COUNT(*) FROM players');
		$this->http->reset();
		$this->dispatch($this->privateMessage($this->owner, 'ПослеОтмены 4'));
		self::assertStringNotContainsString('Добавила', $this->allTexts());
		self::assertSame($before, (int) $this->db->fetchValue('SELECT COUNT(*) FROM players'));

		$this->dispatch($this->privateMessage($this->owner, '/players_import'));
		$this->say($this->owner, '/help');
		$before = (int) $this->db->fetchValue('SELECT COUNT(*) FROM players');
		$this->http->reset();
		$this->dispatch($this->privateMessage($this->owner, 'ПослеХелпа 4'));
		self::assertStringNotContainsString('Добавила', $this->allTexts());
		self::assertSame($before, (int) $this->db->fetchValue('SELECT COUNT(*) FROM players'));
	}

	public function testLongReplyIsSplitIntoSeveralMessages(): void {
		$lines = [];
		for ($i = 1; $i <= 220; $i++) {
			$lines[] = sprintf('LongImportPlayer%03d', $i);
		}
		$this->http->reset();
		$this->dispatch($this->privateMessage($this->owner, "/players_import\n" . implode("\n", $lines)));
		$texts = array_column($this->http->sent('sendMessage'), 'text');
		self::assertGreaterThan(1, count($texts), 'a long import is split');
		foreach ($texts as $text) {
			self::assertLessThanOrEqual(4096, mb_strlen($text));
		}
		self::assertStringContainsString('<b>Добавила</b> (220):', $texts[0]);
		self::assertStringContainsString('LongImportPlayer001 — БТР (3)', $this->allTexts());
		self::assertStringContainsString('LongImportPlayer220 — БТР (3)', end($texts));
		self::assertNotNull($this->app->players->findByIgnNorm('longimportplayer220'));
	}

	public function testHelpListsTheCommandForTheOwnerOnly(): void {
		$help = \P1Bot\View\Texts::help('pop1_bot', true, true);
		self::assertStringContainsString('/players_import', $help);
		self::assertStringNotContainsString('/players_import', \P1Bot\View\Texts::help('pop1_bot', true, false));
	}
}
