<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\App\TeamForm;
use P1Bot\App\WebAppFormError;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Tests\Support\IntegrationTestCase;

/**
	Team Mini App: roles, new/edit saves, letters, prefill, contacts round trip, entry points.
*/
class TeamFormTest extends IntegrationTestCase {

	protected function config(): array {
		$c = parent::config();
		$c['telegram']['webhook_url'] = 'https://pop1.example.com/webhook.php';
		$c['openai']['api_key'] = 'sk-test';
		return $c;
	}

	protected function tokenOf(array $button): string {
		$url = (string) ($button['web_app']['url'] ?? '');
		self::assertStringStartsWith('https://pop1.example.com/webapp/team.html?ctx=', $url);
		parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
		return (string) $q['ctx'];
	}

	protected function formToken(Player $who, Tournament $t, ?int $team_id, ?array $draft = null): string {
		$button = $this->app->team_form->button($who, $this->tgIdOf($who), $t->id, $team_id, $draft);
		self::assertIsArray($button);
		return $this->tokenOf($button);
	}

	protected function boot(Player $who, string $token): array {
		return $this->app->team_form->bootstrap($this->initDataFor($who), $token);
	}

	/**
		@param array<string, mixed> $form
		@return WebAppFormError|null
	*/
	protected function submit(Player $who, string $token, array $form): ?WebAppFormError {
		$this->app->chats->ensure((int) $who->tg_user_id, $who->display_name);
		try {
			$this->app->team_form->submit($this->initDataFor($who), $token, $form);
		} catch (WebAppFormError $e) {
			return $e;
		}
		return null;
	}

	protected function tournamentBy(Player $organizer, int $team_size = 3, ?int $max_points = 10): Tournament {
		$balance_id = $max_points === null ? null : $this->app->balances->viewScaleId();
		$t = $this->app->tournament_repo->create([
			'chat_id' => self::CHAT_ID,
			'organizer_id' => $organizer->id,
			'title' => 'Кубок выходного дня',
			'starts_at' => \P1Bot\Domain\MoscowTime::parse('2026-11-14 19:00'),
			'team_size' => $team_size,
			'balance_id' => $balance_id,
			'reference_team' => null,
			'max_points' => $max_points,
			'max_players' => 24,
			'status' => TournamentStatus::DRAFT,
		], $this->clock->now());
		return $this->app->tournaments->openRegistration($this->owner->id, $t->id);
	}

	/**
		@return list<array<string, mixed>>
	*/
	protected function buttons(): array {
		$out = [];
		foreach ($this->http->sent('sendMessage') as $msg) {
			foreach ($msg['reply_markup']['inline_keyboard'] ?? [] as $row) {
				foreach ($row as $b) {
					$out[] = $b;
				}
			}
		}
		return $out;
	}

	/**
		@return list<int> chats that received a message
	*/
	protected function recipients(): array {
		return array_values(array_unique(array_column($this->http->sent('sendMessage'), 'chat_id')));
	}

	public function testRolesAtBootstrap(): void {
		$org = $this->player('org');
		$cap = $this->player('cap');
		$mate = $this->player('mate');
		$stranger = $this->player('stranger');
		$t = $this->tournamentBy($org);
		$entry = $this->app->teams->createTeam($t->id, $cap->id, 'Секта', [$mate->id]);
		$other = $this->app->teams->createTeam($t->id, $stranger->id, 'Волки', []);

		$b = $this->boot($cap, $this->formToken($cap, $t, $entry->team->id));
		self::assertSame(['edit', 'captain', false, false], [$b['mode'], $b['role'], $b['readonly'], $b['can_letter']]);
		self::assertSame('Секта', $b['team_name_saved']);
		self::assertSame('A', $b['team']['letter']);
		self::assertTrue($b['can_disband']);
		self::assertFalse($b['can_leave']);
		self::assertSame('Кубок выходного дня · Сб 14.11 19:00 МСК · по 3 игрока · уровень 10', $b['tournament']['subtitle']);
		self::assertSame([$cap->id, $mate->id], array_column($b['team']['members'], 'id'));

		$b = $this->boot($mate, $this->formToken($mate, $t, $entry->team->id));
		self::assertSame(['member', true, false, true], [$b['role'], $b['readonly'], $b['can_disband'], $b['can_leave']]);

		foreach ([$org, $this->owner] as $manager) {
			$b = $this->boot($manager, $this->formToken($manager, $t, $entry->team->id));
			self::assertSame(['organizer', false, true], [$b['role'], $b['readonly'], $b['can_letter']]);
			$taken = array_column(array_filter($b['letters'], static fn (array $l): bool => $l['taken']), 'letter');
			self::assertSame(['B'], array_values($taken), 'another team holds B; own A is free to keep');
			self::assertSame(TeamForm::LETTER_HINT, $b['letter_hint']);
		}
		self::assertSame('B', $this->app->teams->get($other->team->id)->team->letter);

		try {
			$this->boot($stranger, $this->formToken($stranger, $t, $entry->team->id));
			self::fail('stranger must not open another team');
		} catch (WebAppFormError $e) {
			self::assertSame(WebAppFormError::FORBIDDEN, $e->reason);
		}
		$intruder = $this->player('intruder');
		try {
			$this->boot($intruder, $this->formToken($cap, $t, $entry->team->id));
			self::fail('signed for another user');
		} catch (WebAppFormError $e) {
			self::assertSame(WebAppFormError::FORBIDDEN, $e->reason);
		}
	}

	public function testNewTeamSubmitCreatesTeamWithLetterAndNoMemberNotifications(): void {
		$t = $this->openTournament(3);
		$cap = $this->player('cap');
		$mate = $this->player('mate');
		$this->app->players->markDmStarted($mate->id, $this->clock->now());
		$token = $this->formToken($cap, $t, null);
		$b = $this->boot($cap, $token);
		self::assertSame(['new', 'captain'], [$b['mode'], $b['role']]);
		self::assertSame([$cap->id], array_column($b['team']['members'], 'id'), 'the opener is captain');
		self::assertFalse($b['can_letter']);

		$this->http->reset();
		self::assertNull($this->submit($cap, $token, ['name' => 'Секта', 'player_ids' => [$cap->id, $mate->id], 'captain_id' => $cap->id]));
		$entry = $this->app->teams->findByName($t->id, 'Секта');
		self::assertNotNull($entry);
		self::assertSame($cap->id, $entry->team->captain_id);
		self::assertSame('A', $entry->team->letter);
		self::assertFalse($entry->team->isRegistered(), 'incomplete team stays «собирается»');
		self::assertSame([$this->tgIdOf($cap)], $this->recipients(), 'the added member gets no message');
		self::assertStringContainsString('🛡 A. <b>Секта</b>', $this->http->lastText());
		$card_buttons = $this->buttons();
		self::assertCount(1, $card_buttons);
		self::assertSame(TeamForm::EDIT_BUTTON, $card_buttons[0]['text']);

		// One team per opening: the same token cannot register again.
		$e = $this->submit($cap, $token, ['name' => 'Секта 2', 'player_ids' => [$cap->id], 'captain_id' => $cap->id]);
		self::assertNotNull($e);
		self::assertSame(WebAppFormError::EXPIRED, $e->reason);
	}

	public function testSoloSizeTeamIsCaptainAlone(): void {
		$t = $this->tournamentBy($this->owner, 1, null);
		$cap = $this->player('lone');
		self::assertNull($this->submit($cap, $this->formToken($cap, $t, null), ['name' => 'Один', 'player_ids' => [$cap->id], 'captain_id' => $cap->id]));
		self::assertTrue($this->app->teams->findByName($t->id, 'Один')->team->isRegistered());
	}

	public function testEditSavesAtomicallyWithoutNotifications(): void {
		$t = $this->openTournament(3);
		$cap = $this->player('cap');
		$a = $this->player('aaa');
		$b = $this->player('bbb');
		$c = $this->player('ccc');
		foreach ([$a, $b, $c] as $p) {
			$this->app->players->markDmStarted($p->id, $this->clock->now());
		}
		$this->rate($cap, 3);
		$this->rate($a, 3);
		$this->rate($b, 4);
		$this->rate($c, 4);
		$entry = $this->app->teams->createTeam($t->id, $cap->id, 'Секта', [$a->id, $b->id]);
		self::assertTrue($entry->team->isRegistered());
		$queued = $entry->team->registered_at;

		// Swap b (4) for c (4) at the 10-point limit and hand the crown to a: final roster is valid.
		$this->clock->advance('+1 hour');
		$token = $this->formToken($cap, $t, $entry->team->id);
		$this->http->reset();
		self::assertNull($this->submit($cap, $token, [
			'name' => 'Секта Плюс',
			'player_ids' => [$cap->id, $a->id, $c->id],
			'captain_id' => $a->id,
		]));
		$saved = $this->app->teams->get($entry->team->id);
		self::assertSame('Секта Плюс', $saved->team->name);
		self::assertSame($a->id, $saved->team->captain_id);
		self::assertSame([$cap->id, $a->id, $c->id], array_map(static fn ($m): int => $m->player_id, $saved->members));
		self::assertEquals($queued, $saved->team->registered_at, 'a full team keeps its queue place');
		self::assertSame([$this->tgIdOf($cap)], $this->recipients(), 'no DM to added or removed players');

		// The former captain is now a regular member: read-only form.
		self::assertTrue($this->boot($cap, $this->formToken($cap, $t, $entry->team->id))['readonly']);
		// Removing a member makes the team incomplete.
		self::assertNull($this->submit($a, $this->formToken($a, $t, $entry->team->id), [
			'name' => 'Секта Плюс',
			'player_ids' => [$a->id, $c->id],
			'captain_id' => $a->id,
		]));
		self::assertFalse($this->app->teams->get($entry->team->id)->team->isRegistered());
	}

	public function testServerValidationErrorsGoToFields(): void {
		$t = $this->openTournament(3);
		$cap = $this->player('cap');
		$pro = $this->player('pro');
		$pro2 = $this->player('pro2');
		$busy = $this->player('busy');
		$this->rate($cap, 5);
		$this->rate($pro, 5);
		$this->rate($pro2, 1);
		$this->app->teams->createTeam($t->id, $busy->id, 'Волки', []);
		$token = $this->formToken($cap, $t, null);

		$e = $this->submit($cap, $token, ['name' => 'Pros', 'player_ids' => [$cap->id, $pro->id, $pro2->id], 'captain_id' => $cap->id]);
		self::assertSame('roster', $e?->field);
		self::assertStringContainsString('лимит турнира 10', $e->getMessage());

		$e = $this->submit($cap, $token, ['name' => 'Волки', 'player_ids' => [$cap->id], 'captain_id' => $cap->id]);
		self::assertSame('name', $e?->field);

		$e = $this->submit($cap, $token, ['name' => 'Pros', 'player_ids' => [$cap->id, $busy->id], 'captain_id' => $cap->id]);
		self::assertSame('roster', $e?->field);
		self::assertStringContainsString('Волки', $e->getMessage());

		$e = $this->submit($cap, $token, ['name' => 'Pros', 'player_ids' => [$cap->id, $pro2->id, $this->player('x1')->id, $this->player('x2')->id], 'captain_id' => $cap->id]);
		self::assertSame('roster', $e?->field);
		self::assertStringContainsString('максимум 3', $e->getMessage());

		$e = $this->submit($cap, $token, ['name' => 'Pros', 'player_ids' => [$pro2->id], 'captain_id' => $pro2->id]);
		self::assertSame(WebAppFormError::FORBIDDEN, $e?->reason, 'a regular player cannot make someone else captain of a new team');

		// Close window: 20 minutes before the start regular players cannot change lineups.
		$this->clock->advance('+5 days +3 hours +45 minutes'); // 2026-11-14 18:45 МСК
		$e = $this->submit($cap, $this->formToken($cap, $t, null), ['name' => 'Late', 'player_ids' => [$cap->id], 'captain_id' => $cap->id]);
		self::assertNotNull($e);
		self::assertNull($this->app->teams->findByName($t->id, 'Late'));
	}

	public function testLettersAllocateChangeStayUniqueAndFree(): void {
		$org = $this->player('org');
		$t = $this->tournamentBy($org, 1, null);
		$caps = [];
		foreach (['p1', 'p2', 'p3'] as $u) {
			$caps[$u] = $this->player($u);
			$this->app->teams->createTeam($t->id, $caps[$u]->id, 'T' . $u, []);
		}
		$letters = fn (): array => array_map(static fn ($team): ?string => $team->letter, $this->app->team_repo->listByTournament($t->id));
		self::assertSame(['A', 'B', 'C'], $letters());

		$team2 = $this->app->teams->teamOf($t->id, $caps['p2']->id);
		$e = $this->submit($caps['p2'], $this->formToken($caps['p2'], $t, $team2->team->id), [
			'name' => 'Tp2', 'player_ids' => [$caps['p2']->id], 'captain_id' => $caps['p2']->id,
			'letter' => 'Z', 'letter_touched' => true,
		]);
		self::assertSame(WebAppFormError::FORBIDDEN, $e?->reason, 'only the organizer assigns letters');

		$token = $this->formToken($org, $t, $team2->team->id);
		$e = $this->submit($org, $token, ['name' => 'Tp2', 'player_ids' => [$caps['p2']->id], 'captain_id' => $caps['p2']->id, 'letter' => 'A', 'letter_touched' => true]);
		self::assertSame('letter', $e?->field);
		self::assertNull($this->submit($org, $token, ['name' => 'Tp2', 'player_ids' => [$caps['p2']->id], 'captain_id' => $caps['p2']->id, 'letter' => 'Z', 'letter_touched' => true]));
		self::assertSame(['A', 'Z', 'C'], $letters());

		// Disbanding frees the letter for the next new team.
		$team1 = $this->app->teams->teamOf($t->id, $caps['p1']->id);
		$this->app->teams->withdrawTeam($caps['p1']->id, $team1->team->id);
		$this->app->teams->createTeam($t->id, $this->player('p4')->id, 'Tp4', []);
		self::assertSame(['Z', 'C', 'A'], $letters());

		// The database itself refuses a duplicate letter.
		$this->expectException(\PDOException::class);
		$this->db->run('UPDATE teams SET letter = :l WHERE id = :id', ['l' => 'C', 'id' => $team2->team->id]);
	}

	public function testMoreThan26TeamsGetNoLetter(): void {
		$t = $this->tournamentBy($this->owner, 1, null);
		for ($i = 1; $i <= 27; $i++) {
			$this->app->teams->createTeam($t->id, $this->player('solo' . $i)->id, 'S' . $i, []);
		}
		$last = $this->app->teams->findByName($t->id, 'S27');
		self::assertNull($last->team->letter);
		self::assertStringContainsString('?. <b>S27</b>', implode("\n", $this->app->announcer->infoMessages($t)));
		self::assertStringContainsString('Z. <b>S26</b>', implode("\n", $this->app->announcer->infoMessages($t)));
	}

	public function testLetterBackfillFollowsDisplayOrder(): void {
		$t = $this->openTournament(1, null);
		$t2 = $this->openTournament(1, null);
		$f = $this->app->teams->createTeam($t->id, $this->player('f')->id, 'Forming', []);
		$this->db->run("UPDATE teams SET status = 'forming', registered_at = NULL WHERE id = :id", ['id' => $f->team->id]);
		$late = $this->app->teams->createTeam($t->id, $this->player('late')->id, 'Late', []);
		$early = $this->app->teams->createTeam($t->id, $this->player('early')->id, 'Early', []);
		$this->db->run("UPDATE teams SET registered_at = '2026-11-01 10:00:00' WHERE id = :id", ['id' => $early->team->id]);
		$other = $this->app->teams->createTeam($t2->id, $this->player('other')->id, 'Other', []);
		$this->db->run('UPDATE teams SET letter = NULL');

		$sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/017_team_letters.sql');
		self::assertSame(1, preg_match('/UPDATE teams SET letter = .*?WHERE letter IS NULL;/s', $sql, $m));
		$this->db->pdo()->exec($m[0]);

		$letter = fn (int $id): ?string => $this->app->team_repo->find($id)->letter;
		self::assertSame('A', $letter($early->team->id));
		self::assertSame('B', $letter($late->team->id));
		self::assertSame('C', $letter($f->team->id), 'forming teams after registered ones');
		self::assertSame('A', $letter($other->team->id), 'letters restart per tournament');
	}

	public function testNamePrefillFromLastTeam(): void {
		$cap = $this->player('cap');
		$old = $this->openTournament(1, null);
		$this->app->teams->createTeam($old->id, $cap->id, 'Секта', []);
		$this->app->tournament_repo->update($old->id, ['status' => TournamentStatus::FINISHED]);
		$t = $this->app->tournament_repo->create([
			'chat_id' => self::CHAT_ID,
			'organizer_id' => $this->owner->id,
			'title' => 'Новый кубок',
			'starts_at' => \P1Bot\Domain\MoscowTime::parse('2026-11-21 19:00'),
			'team_size' => 3,
			'balance_id' => null,
			'reference_team' => null,
			'max_points' => null,
			'max_players' => 24,
			'status' => TournamentStatus::DRAFT,
		], $this->clock->now());
		$t = $this->app->tournaments->openRegistration($this->owner->id, $t->id);
		$b = $this->boot($cap, $this->formToken($cap, $t, null));
		self::assertSame('Секта', $b['team']['name']);
		self::assertSame('Подставила последнее название твоей команды, можно поменять.', $b['name_hint']);

		$this->app->teams->createTeam($t->id, $this->player('thief')->id, 'Секта', []);
		$b = $this->boot($cap, $this->formToken($cap, $t, null));
		self::assertSame('', $b['team']['name'], 'a name already taken here is not offered');
		self::assertNull($b['name_hint']);
	}

	public function testSearchShowsPlayersOnOtherTeamsDisabled(): void {
		$t = $this->openTournament(3);
		$cap = $this->player('cap');
		$astana = $this->player('astana');
		$this->player('stas66');
		$tired = $this->player('just_tired_angel');
		$this->app->nicks->setOwn($tired->id, 'tired angel');
		$this->app->teams->createTeam($t->id, $astana->id, 'Волки', []);
		$token = $this->formToken($cap, $t, null);
		$found = $this->app->team_form->search($this->initDataFor($cap), $token, 'tired_angel');
		self::assertSame($tired->id, $found['players'][0]['id'], 'space/underscore-insensitive');
		$found = $this->app->team_form->search($this->initDataFor($cap), $token, 'astana');
		self::assertSame('Волки', $found['players'][0]['in_team']);
		self::assertSame('средний', $found['players'][0]['level_name']);
	}

	public function testContactsRoundTrip(): void {
		$t = $this->openTournament(3);
		$cap = $this->player('cap');
		$mate = $this->player('mate');
		$token = $this->formToken($cap, $t, null);
		$this->app->chats->ensure($this->tgIdOf($cap), 'cap');
		$this->http->reset();
		$this->app->team_form->contacts($this->initDataFor($cap), $token, [
			'name' => 'Несохранённые', 'player_ids' => [$cap->id, $mate->id], 'captain_id' => $cap->id, 'pending' => ['стас'],
		]);
		$sent = $this->http->sent('sendMessage');
		$request = end($sent)['reply_markup']['keyboard'][0][0]['request_users'];
		self::assertSame(TeamForm::CONTACTS_REQUEST_ID, $request['request_id']);
		self::assertSame(1, $request['max_quantity']);
		self::assertSame([], $this->app->team_repo->listByTournament($t->id), 'nothing saved yet');

		$this->http->reset();
		$this->dispatch($this->privateMessage($cap, '', ['users_shared' => [
			'request_id' => TeamForm::CONTACTS_REQUEST_ID,
			'users' => [['user_id' => 777001, 'username' => 'stas66', 'first_name' => 'Стас']],
		]]));
		$texts = implode("\n", array_column($this->http->sent('sendMessage'), 'text'));
		self::assertStringContainsString('Добавила в форму', $texts);
		$reopen = $this->buttons();
		self::assertSame($token, $this->tokenOf(end($reopen)), 'reopens the same form state');
		$b = $this->boot($cap, $token);
		self::assertSame('Несохранённые', $b['team']['name']);
		$stas = $this->app->players->findByTelegramId(777001);
		self::assertSame([$cap->id, $mate->id, $stas->id], array_column($b['team']['members'], 'id'));
		self::assertSame([], $this->app->team_repo->listByTournament($t->id), 'still nothing saved');
		self::assertSame('стас', $b['pending'][0]['label']);
	}

	public function testFreeTextRegistrationOpensPrefilledForm(): void {
		$t = $this->openTournament(3);
		$cap = $this->player('kopitoshka');
		$tired = $this->player('just_tired_angel');
		$this->app->nicks->setOwn($tired->id, 'tired angel');
		$this->app->players->ensureByTelegram($this->next_tg_id++, 'rustas66', 'Стас Иванов', $this->clock->now());
		$this->app->players->ensureByTelegram($this->next_tg_id++, 'stasyan_p1', 'Стас Петров', $this->clock->now());
		$this->http->queueOpenAi(['id' => 'r1', 'output' => [[
			'type' => 'function_call', 'id' => 'fc', 'call_id' => 'c1', 'name' => 'register_team',
			'arguments' => json_encode(['tournament_id' => $t->id, 'players' => ['я', 'tired angel', 'стас']], JSON_UNESCAPED_UNICODE),
		]]]);
		$this->http->reset();
		$this->dispatch($this->privateMessage($cap, 'запиши нас: я, tired angel и стас'));
		self::assertCount(1, array_filter($this->http->requests, static fn (array $r): bool => str_ends_with($r['url'], '/responses')), 'no second model round');
		$text = $this->http->lastText();
		self::assertStringContainsString('Открой форму команды: уже добавила тебя и tired angel.', $text);
		self::assertStringContainsString('«стас»: нашла 2 варианта, выбери нужного там.', $text);
		self::assertStringNotContainsString('Может, ты про', $text);
		self::assertStringNotContainsString('Не нашла', $text);
		$button = $this->buttons()[0];
		self::assertSame(TeamForm::NEW_BUTTON, $button['text']);
		self::assertSame([], $this->app->team_repo->listByTournament($t->id), 'nothing registered from chat');
		$b = $this->boot($cap, $this->tokenOf($button));
		self::assertSame([$cap->id, $tired->id], array_column($b['team']['members'], 'id'));
		self::assertSame('стас', $b['pending'][0]['label']);
		self::assertCount(2, $b['pending'][0]['suggestions']);
	}

	public function testEditToolsOpenFormWithProposedChanges(): void {
		$t = $this->openTournament(3);
		$cap = $this->player('cap');
		$mate = $this->player('mate');
		$newbie = $this->player('newbie');
		$entry = $this->app->teams->createTeam($t->id, $cap->id, 'Секта', [$mate->id]);
		$cases = [
			['add_player', ['player' => '@newbie'], fn (array $b) => self::assertContains($newbie->id, array_column($b['team']['members'], 'id'))],
			['rename_team', ['new_name' => 'Новая Секта'], fn (array $b) => self::assertSame('Новая Секта', $b['team']['name'])],
			['remove_player', ['player' => '@mate'], fn (array $b) => self::assertSame([$cap->id], array_column($b['team']['members'], 'id'))],
			['transfer_captain', ['player' => '@mate'], fn (array $b) => self::assertSame($mate->id, $b['team']['captain_id'])],
		];
		foreach ($cases as [$tool, $args, $check]) {
			$this->http->queueOpenAi(['id' => 'r' . $tool, 'output' => [[
				'type' => 'function_call', 'id' => 'fc', 'call_id' => 'c' . $tool, 'name' => $tool,
				'arguments' => json_encode($args + ['tournament_id' => $t->id], JSON_UNESCAPED_UNICODE),
			]]]);
			$this->http->reset();
			$this->dispatch($this->privateMessage($cap, 'поменяй команду'));
			self::assertStringContainsString('Открой форму команды «Секта»', $this->http->lastText(), $tool);
			$check($this->boot($cap, $this->tokenOf($this->buttons()[0])));
			$saved = $this->app->teams->get($entry->team->id);
			self::assertSame('Секта', $saved->team->name, $tool . ' changes nothing before the form is saved');
			self::assertSame(2, $saved->size());
		}
	}

	public function testUsernameAskSafetyNetOpensForm(): void {
		$t = $this->openTournament(3);
		$cap = $this->player('cap');
		$this->http->queueOpenAi(['id' => 'r1', 'output' => [[
			'type' => 'message', 'role' => 'assistant',
			'content' => [['type' => 'output_text', 'text' => 'Пришли, пожалуйста, их @username в Telegram.']],
		]]]);
		$this->http->reset();
		$this->dispatch($this->privateMessage($cap, 'запиши нас: я, tired angel и стас'));
		self::assertStringContainsString('Открой форму команды', $this->http->lastText());
		self::assertStringNotContainsString('@username', $this->http->lastText());
		self::assertSame(TeamForm::NEW_BUTTON, $this->buttons()[0]['text']);
		self::assertSame([], $this->app->team_repo->listByTournament($t->id));
	}

	public function testEntryPointsUseTheForm(): void {
		$t = $this->openTournament(3);
		$cap = $this->player('cap');
		$mate = $this->player('mate');
		$this->http->reset();
		$this->dispatch($this->privateMessage($cap, '/start t_' . $t->id));
		$texts = array_column($this->buttons(), 'text');
		self::assertSame([TeamForm::NEW_BUTTON, '🙋 Участвовать соло'], $texts);
		self::assertArrayHasKey('web_app', $this->buttons()[0]);

		$entry = $this->app->teams->createTeam($t->id, $cap->id, 'Секта', [$mate->id]);
		$this->http->reset();
		$this->dispatch($this->privateMessage($mate, '/start t_' . $t->id));
		self::assertSame('✏️ Редактировать команду «Секта»', $this->buttons()[0]['text']);
		self::assertTrue($this->boot($mate, $this->tokenOf($this->buttons()[0]))['readonly']);

		// Organizer list: stored letters, each team opens its form.
		$this->http->reset();
		$this->dispatch($this->press($this->owner, 'oteams:' . $t->id));
		self::assertStringContainsString('A. Секта — 2/3', $this->http->lastText());
		$list = $this->buttons();
		self::assertStringStartsWith('A. 🛡 Секта', $list[0]['text']);
		self::assertSame($entry->team->id, $this->boot($this->owner, $this->tokenOf($list[0]))['team']['id']);
		self::assertSame('new', $this->boot($this->owner, $this->tokenOf(end($list)))['mode']);

		// Old roster buttons only send the form; nothing changes.
		foreach (['padd:', 'prem:', 'otren:', 'otcap:', 'otdel:'] as $old) {
			$this->http->reset();
			$this->dispatch($this->press($cap, $old . $entry->team->id));
			self::assertSame(TeamForm::EDIT_BUTTON, $this->buttons()[0]['text'], $old);
		}
		$this->dispatch($this->press($cap, 'trem:' . $entry->team->id . ':' . $mate->id));
		$this->dispatch($this->press($cap, 'tadd:' . $entry->team->id . ':' . $this->player('extra')->id));
		self::assertSame(2, $this->app->teams->get($entry->team->id)->size());
	}

	public function testGroupLinkContinuesInPrivate(): void {
		$t = $this->openTournament(3);
		$cap = $this->player('cap');
		$button = $this->app->team_form->button($cap, self::CHAT_ID, $t->id, null, ['name' => 'Из группы', 'player_ids' => [$cap->id], 'captain_id' => $cap->id]);
		self::assertArrayNotHasKey('web_app', $button, 'groups never get web_app buttons');
		self::assertSame(1, preg_match('/start=tf_([a-f0-9]{32})$/', $button['url'], $m));
		$this->http->reset();
		$this->dispatch($this->privateMessage($cap, '/start tf_' . $m[1]));
		$open = $this->buttons()[0];
		self::assertSame('Из группы', $this->boot($cap, $this->tokenOf($open))['team']['name']);

		$this->http->reset();
		$this->dispatch($this->privateMessage($this->player('other'), '/start tf_' . $m[1]));
		self::assertSame(TeamForm::EXPIRED_TEXT, $this->http->lastText(), 'a link for someone else');
	}

	public function testMemberLeavesAndCaptainDisbands(): void {
		$t = $this->openTournament(3);
		$cap = $this->player('cap');
		$mate = $this->player('mate');
		$third = $this->player('third');
		$this->app->players->markDmStarted($cap->id, $this->clock->now());
		$this->app->players->markDmStarted($third->id, $this->clock->now());
		$entry = $this->app->teams->createTeam($t->id, $cap->id, 'Секта', [$mate->id, $third->id]);
		$this->http->reset();
		$this->app->team_form->leave($this->initDataFor($mate), $this->formToken($mate, $t, $entry->team->id));
		self::assertNull($this->app->teams->teamOf($t->id, $mate->id));
		$dm = array_values(array_filter($this->http->sent('sendMessage'), fn (array $m): bool => $m['chat_id'] === $this->tgIdOf($cap)));
		self::assertCount(1, $dm, 'the captain hears about it');

		try {
			$this->app->team_form->disband($this->initDataFor($third), $this->formToken($third, $t, $entry->team->id));
			self::fail('a member cannot disband');
		} catch (WebAppFormError $e) {
			self::assertSame(WebAppFormError::FORBIDDEN, $e->reason);
		}
		$this->http->reset();
		$this->app->team_form->disband($this->initDataFor($cap), $this->formToken($cap, $t, $entry->team->id));
		self::assertNull($this->app->teams->findByName($t->id, 'Секта'));
		$dm = array_values(array_filter($this->http->sent('sendMessage'), fn (array $m): bool => $m['chat_id'] === $this->tgIdOf($third)));
		self::assertStringContainsString('Капитан распустил команду «Секта»', $dm[0]['text']);
	}

	public function testOrganizerBuildsTeamForSomeoneElse(): void {
		$t = $this->openTournament(3);
		$cap = $this->player('cap');
		$token = $this->formToken($this->owner, $t, null);
		self::assertTrue($this->boot($this->owner, $token)['can_transfer']);
		self::assertNull($this->submit($this->owner, $token, ['name' => 'Чужие', 'player_ids' => [$cap->id], 'captain_id' => $cap->id, 'letter' => 'K', 'letter_touched' => true]));
		$entry = $this->app->teams->findByName($t->id, 'Чужие');
		self::assertSame($cap->id, $entry->team->captain_id);
		self::assertSame('K', $entry->team->letter);
		self::assertNull($this->app->teams->teamOf($t->id, $this->owner->id));
	}

	public function testOrganizerBuildsTeamWithoutThemselves(): void {
		$t = $this->openTournament(3);
		$a = $this->player('aaa');
		$b = $this->player('bbb');
		$token = $this->formToken($this->owner, $t, null);
		$boot = $this->boot($this->owner, $token);
		self::assertTrue($boot['can_remove_any']);
		self::assertSame([$this->owner->id], array_column($boot['team']['members'], 'id'), 'not in a team yet: starts with themselves');
		// Captain left out: the first player of the roster becomes captain.
		self::assertNull($this->submit($this->owner, $token, ['name' => 'Без меня', 'player_ids' => [$a->id, $b->id], 'captain_id' => $this->owner->id]));
		$entry = $this->app->teams->findByName($t->id, 'Без меня');
		self::assertSame($a->id, $entry->team->captain_id);
		self::assertSame([$a->id, $b->id], array_map(static fn ($m): int => $m->player_id, $entry->members));
		self::assertNull($this->app->teams->teamOf($t->id, $this->owner->id));
	}

	public function testOrganizerAlreadyInTeamStartsWithEmptyRoster(): void {
		$org = $this->player('org');
		$old = $this->tournamentBy($org, 1, null);
		$this->app->teams->createTeam($old->id, $org->id, 'Старая', []);
		$this->app->tournament_repo->update($old->id, ['status' => \P1Bot\Domain\TournamentStatus::FINISHED]);
		$t = $this->app->tournament_repo->create([
			'chat_id' => self::CHAT_ID, 'organizer_id' => $org->id, 'title' => 'Новый',
			'starts_at' => \P1Bot\Domain\MoscowTime::parse('2026-11-21 19:00'), 'team_size' => 3,
			'balance_id' => null, 'reference_team' => null, 'max_points' => null, 'max_players' => 24,
			'status' => \P1Bot\Domain\TournamentStatus::DRAFT,
		], $this->clock->now());
		$t = $this->app->tournaments->openRegistration($this->owner->id, $t->id);
		$own = $this->app->teams->createTeam($t->id, $org->id, 'Моя', []);
		$a = $this->player('aaa');
		$b = $this->player('bbb');

		$token = $this->formToken($org, $t, null);
		$boot = $this->boot($org, $token);
		self::assertSame(['new', 'organizer'], [$boot['mode'], $boot['role']]);
		self::assertSame([], $boot['team']['members'], 'not pre-inserted as the first member');
		self::assertSame(0, $boot['team']['captain_id']);
		self::assertSame('', $boot['team']['name'], 'no prefill from their own old team');
		self::assertNull($boot['name_hint']);

		$e = $this->submit($org, $token, ['name' => 'Пустая', 'player_ids' => [], 'captain_id' => 0]);
		self::assertSame('roster', $e?->field);
		self::assertSame(\P1Bot\Domain\TeamService::EMPTY_ROSTER, $e->getMessage());

		self::assertNull($this->submit($org, $token, ['name' => 'Чужие', 'player_ids' => [$a->id, $b->id], 'captain_id' => $b->id]));
		$entry = $this->app->teams->findByName($t->id, 'Чужие');
		self::assertSame($b->id, $entry->team->captain_id, 'picked with «сделать капитаном»');
		self::assertSame($own->team->id, $this->app->teams->teamOf($t->id, $org->id)->team->id, 'the organizer stays in their own team');
	}

	public function testOrganizerRemovesCaptainAndFirstPlayerTakesOver(): void {
		$t = $this->openTournament(3);
		$cap = $this->player('cap');
		$a = $this->player('aaa');
		$b = $this->player('bbb');
		$entry = $this->app->teams->createTeam($t->id, $cap->id, 'Секта', [$a->id, $b->id]);
		self::assertNull($this->submit($this->owner, $this->formToken($this->owner, $t, $entry->team->id), [
			'name' => 'Секта', 'player_ids' => [$b->id, $a->id], 'captain_id' => $cap->id,
		]));
		$saved = $this->app->teams->get($entry->team->id);
		self::assertSame($b->id, $saved->team->captain_id);
		self::assertSame(2, $saved->size());
		$e = $this->submit($this->owner, $this->formToken($this->owner, $t, $entry->team->id), [
			'name' => 'Секта', 'player_ids' => [], 'captain_id' => 0,
		]);
		self::assertSame(\P1Bot\Domain\TeamService::EMPTY_ROSTER, $e?->getMessage());
		self::assertSame(2, $this->app->teams->get($entry->team->id)->size());
	}

	public function testRegularCaptainCannotRemoveThemselves(): void {
		$t = $this->openTournament(3);
		$cap = $this->player('cap');
		$a = $this->player('aaa');
		$entry = $this->app->teams->createTeam($t->id, $cap->id, 'Секта', [$a->id]);
		$boot = $this->boot($cap, $this->formToken($cap, $t, $entry->team->id));
		self::assertFalse($boot['can_remove_any']);
		// Even after handing over the crown in the same save.
		$e = $this->submit($cap, $this->formToken($cap, $t, $entry->team->id), [
			'name' => 'Секта', 'player_ids' => [$a->id], 'captain_id' => $a->id,
		]);
		self::assertSame(\P1Bot\Domain\TeamService::SELF_REMOVAL, $e?->getMessage());
		self::assertSame('roster', $e->field);
		$saved = $this->app->teams->get($entry->team->id);
		self::assertSame([$cap->id, 2], [$saved->team->captain_id, $saved->size()]);

		$new = $this->player('newcap');
		$token = $this->formToken($new, $t, null);
		self::assertFalse($this->boot($new, $token)['can_remove_any']);
		$e = $this->submit($new, $token, ['name' => 'Пусто', 'player_ids' => [], 'captain_id' => 0]);
		self::assertSame(\P1Bot\Domain\TeamService::EMPTY_ROSTER, $e?->getMessage());
		$e = $this->submit($new, $token, ['name' => 'Без себя', 'player_ids' => [$this->player('x')->id], 'captain_id' => 0]);
		self::assertSame(WebAppFormError::FORBIDDEN, $e?->reason);
	}

	protected function tournamentAt(string $starts_msk, string $title): \P1Bot\Domain\Model\Tournament {
		$t = $this->app->tournament_repo->create([
			'chat_id' => self::CHAT_ID, 'organizer_id' => $this->owner->id, 'title' => $title,
			'starts_at' => \P1Bot\Domain\MoscowTime::parse($starts_msk), 'team_size' => 3,
			'balance_id' => null, 'reference_team' => null, 'max_points' => null, 'max_players' => 24,
			'status' => TournamentStatus::DRAFT,
		], $this->clock->now());
		return $this->app->tournaments->openRegistration($this->owner->id, $t->id);
	}

	protected function prefill(Player $who, \P1Bot\Domain\Model\Tournament $t): string {
		return (string) $this->boot($who, $this->formToken($who, $t, null))['team']['name'];
	}

	public function testPreferredNameIsTheLatestRegisteredTeamWhateverTheStart(): void {
		$cap = $this->player('kopitoshka');
		$past = $this->tournamentAt('2026-11-10 19:00', 'Старый');
		$this->app->teams->createTeam($past->id, $cap->id, 'Драконы', []);
		$this->app->tournament_repo->update($past->id, ['status' => TournamentStatus::FINISHED]);
		$middle = $this->tournamentAt('2026-11-20 19:00', 'Средний');
		$earlier = $this->tournamentAt('2026-11-15 19:00', 'Раньше');
		$later = $this->tournamentAt('2026-11-28 19:00', 'Позже');
		self::assertSame('Драконы', $this->prefill($cap, $middle));

		// Registered through the form: remembered at once.
		self::assertNull($this->submit($cap, $this->formToken($cap, $middle, null), ['name' => 'Секта', 'player_ids' => [$cap->id], 'captain_id' => $cap->id]));
		self::assertSame('Секта', $this->app->team_repo->preferredTeamName($cap->id));
		self::assertSame('Секта', $this->prefill($cap, $earlier), 'a tournament that starts earlier');
		self::assertSame('Секта', $this->prefill($cap, $later), 'a tournament that starts later');

		// Rename by an admin updates it; disbanding does not clear it.
		$entry = $this->app->teams->findByName($middle->id, 'Секта');
		self::assertNull($this->submit($this->owner, $this->formToken($this->owner, $middle, $entry->team->id), ['name' => 'Секта 2', 'player_ids' => [$cap->id], 'captain_id' => $cap->id]));
		self::assertSame('Секта 2', $this->prefill($cap, $later));
		$this->app->teams->renameTeam($cap->id, $entry->team->id, 'Секта 3');
		self::assertSame('Секта 3', $this->prefill($cap, $later));
		$this->app->teams->withdrawTeam($cap->id, $entry->team->id);
		self::assertSame('Секта 3', $this->prefill($cap, $later));
	}

	public function testOrganizerTeamAndCaptainTransferSetTheCaptainsName(): void {
		$t = $this->openTournament(3);
		$later = $this->tournamentAt('2026-11-28 19:00', 'Позже');
		$a = $this->player('aaa');
		$b = $this->player('bbb');
		self::assertNull($this->submit($this->owner, $this->formToken($this->owner, $t, null), ['name' => 'Чужие', 'player_ids' => [$a->id, $b->id], 'captain_id' => $b->id]));
		self::assertSame('Чужие', $this->app->team_repo->preferredTeamName($b->id), 'the chosen captain');
		self::assertNull($this->app->team_repo->preferredTeamName($a->id));
		self::assertNull($this->app->team_repo->preferredTeamName($this->owner->id), 'the organizer is not the captain');
		$entry = $this->app->teams->findByName($t->id, 'Чужие');
		$this->app->teams->transferCaptain($b->id, $entry->team->id, $a->id);
		self::assertSame('Чужие', $this->prefill($a, $later), 'the new captain');
		self::assertSame('Чужие', $this->prefill($b, $later), 'the old captain keeps it');

		$c = $this->player('ccc');
		$this->app->teams->createTeamAsOrganizer($this->owner->id, $t->id, $c->id, 'Третьи', []);
		self::assertSame('Третьи', $this->app->team_repo->preferredTeamName($c->id));
	}

	public function testPreferredNameNotPrefilledWhenTakenOrRosterEmpty(): void {
		$t = $this->openTournament(3);
		$later = $this->tournamentAt('2026-11-28 19:00', 'Позже');
		$cap = $this->player('cap');
		$this->app->teams->createTeam($t->id, $cap->id, 'Секта', []);
		$this->app->teams->createTeam($later->id, $this->player('thief')->id, 'Секта', []);
		$boot = $this->boot($cap, $this->formToken($cap, $later, null));
		self::assertSame(['', null], [$boot['team']['name'], $boot['name_hint']], 'taken in this tournament');

		// The organizer already plays here: empty roster, no prefill of their own team.
		$this->app->teams->createTeam($later->id, $this->owner->id, 'Админы', []);
		self::assertSame('Админы', $this->app->team_repo->preferredTeamName($this->owner->id));
		$boot = $this->boot($this->owner, $this->formToken($this->owner, $later, null));
		self::assertSame([[], '', null], [$boot['team']['members'], $boot['team']['name'], $boot['name_hint']]);
	}

	public function testMigrationBackfillsFromTheLastCaptainedTeam(): void {
		$cap = $this->player('cap');
		$mate = $this->player('mate');
		$t1 = $this->openTournament(3);
		$t2 = $this->tournamentAt('2026-11-25 19:00', 'Другой');
		$old = $this->app->teams->createTeam($t1->id, $cap->id, 'Драконы', [$mate->id]);
		$new = $this->app->teams->createTeam($t2->id, $cap->id, 'Секта', []);
		$this->db->run("UPDATE teams SET created_at = '2026-11-01 10:00:00' WHERE id = :id", ['id' => $old->team->id]);
		$this->db->run("UPDATE teams SET created_at = '2026-11-05 10:00:00' WHERE id = :id", ['id' => $new->team->id]);
		$this->db->run('UPDATE players SET preferred_team_name = NULL');
		$sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/018_preferred_team_name.sql');
		self::assertSame(1, preg_match('/UPDATE players SET preferred_team_name = .*?WHERE preferred_team_name IS NULL;/s', $sql, $m));
		$this->db->pdo()->exec($m[0]);
		self::assertSame('Секта', $this->app->team_repo->preferredTeamName($cap->id), 'latest created, not latest start');
		self::assertNull($this->app->team_repo->preferredTeamName($mate->id), 'only captains');
	}

	public function testOfflinePlayersAreFoundAndAddedButNeverCaptain(): void {
		$t = $this->openTournament(3);
		$shadow = $this->app->nicks->addPlayer($this->owner->id, null, 'Тень')['player'];
		$owl = $this->app->nicks->addPlayer($this->owner->id, 'offline_owl', null)['player'];
		$real = $this->player('real_one');
		$token = $this->formToken($this->owner, $t, null);
		$found = $this->app->team_form->search($this->initDataFor($this->owner), $token, 'тен');
		self::assertSame([$shadow->id, false], [$found['players'][0]['id'] ?? null, $found['players'][0]['can_captain'] ?? null]);
		$found = $this->app->team_form->search($this->initDataFor($this->owner), $token, 'offline_o');
		self::assertSame($owl->id, $found['players'][0]['id'] ?? null);

		// Only players without Telegram: refused with a clear error, nothing saved.
		$e = $this->submit($this->owner, $token, ['name' => 'Призраки', 'player_ids' => [$shadow->id, $owl->id], 'captain_id' => 0]);
		self::assertNotNull($e);
		self::assertStringContainsString('капитаном быть не может', $e->getMessage());
		self::assertNull($this->app->teams->findByName($t->id, 'Призраки'));
		$e = $this->submit($this->owner, $token, ['name' => 'Призраки', 'player_ids' => [$shadow->id, $real->id], 'captain_id' => $shadow->id]);
		self::assertStringContainsString('капитаном быть не может', (string) $e?->getMessage());

		// Default captain: the first player with Telegram, in the bootstrap and on save.
		$draft = $this->formToken($this->owner, $t, null, ['player_ids' => [$shadow->id, $real->id], 'name' => 'Смешанные']);
		$b = $this->boot($this->owner, $draft);
		self::assertSame($real->id, $b['team']['captain_id']);
		self::assertSame([false, true], array_column($b['team']['members'], 'can_captain'));
		self::assertNull($this->submit($this->owner, $token, ['name' => 'Смешанные', 'player_ids' => [$shadow->id, $real->id], 'captain_id' => 0]));
		$entry = $this->app->teams->findByName($t->id, 'Смешанные');
		self::assertSame($real->id, $entry->team->captain_id);
		self::assertCount(2, $entry->members);

		// Captain can add them in edit mode too; transfer to them is refused.
		$edit = $this->formToken($real, $t, $entry->team->id);
		self::assertNull($this->submit($real, $edit, ['name' => 'Смешанные', 'player_ids' => [$shadow->id, $real->id, $owl->id], 'captain_id' => $real->id]));
		$e = $this->submit($real, $edit, ['name' => 'Смешанные', 'player_ids' => [$shadow->id, $real->id, $owl->id], 'captain_id' => $owl->id]);
		self::assertStringContainsString('капитаном быть не может', (string) $e?->getMessage());
		self::assertSame($real->id, $this->app->teams->findByName($t->id, 'Смешанные')->team->captain_id);
	}
}
