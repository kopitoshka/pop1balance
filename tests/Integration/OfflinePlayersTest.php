<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\TeamService;
use P1Bot\Tests\Support\IntegrationTestCase;
use P1Bot\Domain\AccessPolicy;

/**
	Players without Telegram (2026-11-20): /addplayer @username [nick] and /addplayer nick.
*/
class OfflinePlayersTest extends IntegrationTestCase {

	protected function say(Player $who, string $text): string {
		$this->http->reset();
		$this->dispatch($this->privateMessage($who, $text));
		return $this->http->lastText();
	}

	protected function playerCount(): int {
		return (int) $this->db->fetchValue('SELECT COUNT(*) FROM players');
	}

	public function testAddByUsernameWithAndWithoutNick(): void {
		$reply = $this->say($this->owner, '/addplayer @New_Guy Zoloto on heat');
		self::assertSame(
			'Добавила игрока @new_guy с ником «Zoloto on heat». Когда он напишет мне, привяжу его Telegram. '
				. 'До тех пор сообщений от меня он не получает и капитаном быть не может.',
			$reply,
		);
		$p = $this->app->players->findByUsername('new_guy');
		self::assertSame([null, 'new_guy', 'Zoloto on heat', 'Zoloto on heat'], [$p->tg_user_id, $p->username, $p->ign, $p->display_name]);

		$reply = $this->say($this->owner, '/addplayer @plainuser');
		self::assertStringStartsWith('Добавила игрока @plainuser. Когда он напишет мне', $reply);
		$p = $this->app->players->findByUsername('plainuser');
		self::assertSame([null, null, ''], [$p->tg_user_id, $p->ign, $p->display_name]);
	}

	public function testAddByNickOnly(): void {
		$before = $this->playerCount();
		$reply = $this->say($this->owner, '/addplayer Тихий <Охотник>');
		self::assertStringContainsString('В нике можно буквы', $reply, 'nick is validated');
		self::assertSame($before, $this->playerCount());

		$reply = $this->say($this->owner, '/addplayer Тихий Охотник');
		self::assertSame(
			'Добавила игрока «Тихий Охотник» без Telegram. Его можно ставить в составы и задавать уровень '
				. '(<code>/level Тихий Охотник 4</code>), но сообщений от меня он не получит и капитаном быть не может.',
			$reply,
		);
		$p = $this->app->players->findByIgnNorm('тихий охотник');
		self::assertSame([null, null, 'Тихий Охотник'], [$p->tg_user_id, $p->username, $p->display_name]);
		self::assertSame($before + 1, $this->playerCount());
	}

	public function testExistingUsernameIsNotDuplicatedAndGetsTheNick(): void {
		$vasya = $this->player('vasya');
		$petya = $this->player('petya');
		$this->app->nicks->setOwn($petya->id, 'Zoloto');
		$before = $this->playerCount();

		self::assertSame('Игрок @vasya уже есть, без ника.', $this->say($this->owner, '/addplayer @Vasya'));
		self::assertSame(
			'Игрок @vasya уже есть, поставила ему ник «Zoloto». Раньше этот ник был у @petya — забрала.',
			$this->say($this->owner, '/addplayer @vasya Zoloto'),
		);
		self::assertSame($before, $this->playerCount());
		self::assertSame('Zoloto', $this->app->players->find($vasya->id)->ign);
		self::assertNull($this->app->players->find($petya->id)->ign);
		self::assertSame('Игрок @vasya уже есть, ник «Zoloto».', $this->say($this->owner, '/addplayer @vasya'));

		// Added earlier without Telegram: also reused.
		$this->say($this->owner, '/addplayer @ghost_one');
		$this->say($this->owner, '/addplayer @ghost_one Призрак');
		self::assertSame('Призрак', $this->app->players->findByUsername('ghost_one')->ign);
		self::assertSame($before + 1, $this->playerCount());
	}

	public function testNickOnlyNeverTakesSomeonesNick(): void {
		$vasya = $this->player('vasya');
		$this->app->nicks->setOwn($vasya->id, 'Zoloto');
		$before = $this->playerCount();
		self::assertSame(
			'Ник «Zoloto» уже у игрока Zoloto (@vasya). Если это другой человек, дай ему другой ник.',
			$this->say($this->owner, '/addplayer zoloto'),
		);
		self::assertSame($before, $this->playerCount());
		self::assertSame('Zoloto', $this->app->players->find($vasya->id)->ign);
	}

	public function testUsernameFormatIsValidated(): void {
		$before = $this->playerCount();
		foreach (['/addplayer @abc', '/addplayer @1player', '/addplayer @bad-name Ник', '/addplayer @'] as $cmd) {
			self::assertStringContainsString('Username: 5–32 символа', $this->say($this->owner, $cmd), $cmd);
		}
		self::assertStringContainsString('Ник от 2 до 32', $this->say($this->owner, '/addplayer @good_name Z'));
		self::assertSame($before, $this->playerCount(), 'nothing half-created');
	}

	public function testAccessCreatorsAndOwnerOnlyPrivateOnly(): void {
		$creator = $this->player('creator');
		self::assertStringStartsWith('Добавила игрока', $this->say($creator, '/addplayer Новичок'));

		$plain = $this->player('plain');
		$this->withoutSharedOwnerGroup($plain);
		$before = $this->playerCount();
		self::assertSame(
			'Добавлять игроков по имени могут только создатели турниров и главный админ.',
			$this->say($plain, '/addplayer Чужак'),
		);
		self::assertSame($before, $this->playerCount());
		$this->say($plain, '/addplayer');
		self::assertStringContainsString('добавлю их в базу', $this->http->lastText(), 'the picker stays for everyone');

		$this->http->reset();
		$this->dispatch($this->groupMessage($this->owner, '/addplayer Групповой'));
		self::assertStringContainsString('личк', $this->http->lastText(), 'groups redirect to private');
		self::assertNull($this->app->players->findByIgnNorm('групповой'));
	}

	public function testFirstVisitByUsernameBindsTheRow(): void {
		$this->say($this->owner, '/addplayer @late_comer Опоздун');
		$row = $this->app->players->findByUsername('late_comer');
		$this->rate($row, 5);
		$real = new Player(0, 77001, 'Late_Comer', 'Late Comer');
		$this->dispatch($this->privateMessage($real, '/start'));
		$bound = $this->app->players->findByTelegramId(77001);
		self::assertSame($row->id, $bound->id);
		self::assertSame(['late_comer', 'Опоздун', 'Late Comer'], [$bound->username, $bound->ign, $bound->display_name]);
		self::assertSame(5, $this->app->balances->levelOf($this->app->balances->findDefault()->id, $bound->id));
		self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM players WHERE username = 'late_comer'"));
	}

	public function testOfflinePlayersCannotBeCaptain(): void {
		$t = $this->openTournament(3, null);
		$cap = $this->player('cap');
		$this->say($this->owner, '/addplayer Без Телеги');
		$this->say($this->owner, '/addplayer @offline_user');
		$nick = $this->app->players->findByIgnNorm('без телеги');
		$user = $this->app->players->findByUsername('offline_user');

		foreach ([$nick, $user] as $p) {
			try {
				$this->app->teams->createTeamAsOrganizer($this->owner->id, $t->id, $p->id, 'Призраки ' . $p->id);
				self::fail('offline captain');
			} catch (ValidationError $e) {
				self::assertSame(sprintf(TeamService::NO_TELEGRAM_CAPTAIN, $p->mention()), $e->getMessage());
			}
		}
		self::assertSame(
			'Без Телеги без Telegram — капитаном быть не может: я не смогу ему писать. Выбери капитаном того, кто есть в Telegram.',
			sprintf(TeamService::NO_TELEGRAM_CAPTAIN, $nick->mention()),
		);
		$entry = $this->app->teams->createTeam($t->id, $cap->id, 'Живые', [$nick->id, $user->id]);
		self::assertCount(3, $entry->members, 'offline players can be on a roster');
		try {
			$this->app->teams->transferCaptain($cap->id, $entry->team->id, $nick->id);
			self::fail('offline captain via transfer');
		} catch (ValidationError $e) {
			self::assertStringContainsString('капитаном быть не может', $e->getMessage());
		}
		self::assertSame($cap->id, $this->app->teams->get($entry->team->id)->team->captain_id);
	}

	public function testDisbandWithOfflineMembersSkipsThemSilently(): void {
		$t = $this->openTournament(3, null);
		$cap = $this->player('cap');
		$mate = $this->player('mate');
		$this->app->players->markDmStarted($mate->id, $this->clock->now());
		$this->say($this->owner, '/addplayer Тень');
		$shadow = $this->app->players->findByIgnNorm('тень');
		$entry = $this->app->teams->createTeam($t->id, $cap->id, 'Секта', [$mate->id, $shadow->id]);
		$disband = $this->app->teams->withdrawTeam($cap->id, $entry->team->id);
		$this->http->reset();
		$this->app->disband_notifier->notify($cap->id, $disband);
		self::assertSame([$this->tgIdOf($mate)], array_column($this->http->sent('sendMessage'), 'chat_id'));
	}

	public function testLevelAndNickByIgnForOfflinePlayers(): void {
		$this->say($this->owner, '/addplayer Stas 66');
		$stas = $this->app->players->findByIgnNorm('stas 66');
		self::assertSame('Записала: Stas 66 — высокий (5).', $this->say($this->owner, '/level Stas 66 5'));
		self::assertSame(5, $this->app->balances->levelOf($this->app->balances->findDefault()->id, $stas->id));
		self::assertStringStartsWith('Stas 66 — высокий (5)', $this->say($this->owner, '/level stas 66'), 'a nick ending in a digit is a nick, not a level');

		$vasya = $this->player('vasya');
		self::assertSame(AccessPolicy::LEVELS_OWNER_ONLY, $this->say($vasya, '/level Stas 66 2'));
		self::assertSame(5, $this->app->balances->levelOf($this->app->balances->findDefault()->id, $stas->id), 'only the owner sets levels');

		self::assertSame('Поставила ник «Stas Pro» игроку Stas 66.', $this->say($this->owner, '/nick "Stas 66" Stas Pro'));
		self::assertSame('Stas Pro', $this->app->players->find($stas->id)->ign);
		self::assertStringContainsString('ник <b>Stas Pro</b>', $this->say($vasya, '/nick "stas pro"'));
	}

	public function testOfflinePlayersRenderAsPlainTextOrUsernameLink(): void {
		$this->say($this->owner, '/addplayer Тень');
		$this->say($this->owner, '/addplayer @offline_user Сова');
		$shadow = $this->app->players->findByIgnNorm('тень');
		$owl = $this->app->players->findByUsername('offline_user');
		self::assertSame('Тень', $shadow->lineupLabel());
		self::assertSame('<a href="https://t.me/offline_user">Сова</a>', $owl->lineupLabel());
		self::assertSame('<a href="tg://user?id=777">&lt;b&gt;</a>', (new Player(1, 777, null, '<b>'))->lineupLabel());

		$players = $this->say($this->owner, '/players');
		self::assertStringContainsString("• <b>Тень</b>\n", $players . "\n");
		self::assertStringContainsString('• <a href="https://t.me/offline_user"><b>Сова</b></a> · @offline_user', $players);

		$t = $this->openTournament(3, null);
		$cap = $this->player('cap');
		$this->app->teams->createTeam($t->id, $cap->id, 'Секта', [$shadow->id, $owl->id]);
		$info = implode("\n", $this->app->announcer->infoMessages($this->app->tournament_repo->find($t->id)));
		self::assertStringContainsString('Тень', $info);
		self::assertStringNotContainsString('>Тень<', $info, 'no link for a nick-only player');
		self::assertStringContainsString('<a href="https://t.me/offline_user">Сова</a>', $info);
	}

	public function testUsernameOnlyPlayerIsTargetableByUsername(): void {
		$this->say($this->owner, '/addplayer @offline_owl');
		$owl = $this->app->players->findByUsername('offline_owl');
		self::assertSame('Записала: @offline_owl — средний+ (4).', $this->say($this->owner, '/level @offline_owl 4'));
		self::assertSame(4, $this->app->balances->levelOf($this->app->balances->findDefault()->id, $owl->id));
		self::assertSame('Поставила ник «Сова» игроку @offline_owl.', $this->say($this->owner, '/nick @offline_owl Сова'));
		self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM players WHERE username = 'offline_owl'"));
	}

	public function testHelpShowsTheArgumentForms(): void {
		$help = \P1Bot\View\Texts::help('pop1_bot', true, true);
		self::assertStringContainsString('<code>/addplayer @username НикВИгре</code>', $help);
		self::assertStringContainsString('<code>/addplayer НикВИгре</code>', $help);
		self::assertStringContainsString('<code>/level НикВИгре 4</code>', $help);
		self::assertStringNotContainsString('/addplayer @username', \P1Bot\View\Texts::help('pop1_bot', false, false));
	}
}
