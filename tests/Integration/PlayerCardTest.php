<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\App\PlayerForm;
use P1Bot\App\WebAppFormError;
use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Telegram\WebAppInitData;
use P1Bot\Tests\Support\IntegrationTestCase;

/**
	The owner's player card: /players links (✏️), the Mini App form API (owner only, Direct Mini App
	start_param and context token), edit, soft delete, merge.
*/
class PlayerCardTest extends IntegrationTestCase {

	/**
		@var array<string, mixed>
	*/
	protected array $telegram_config = ['webhook_url' => 'https://pop1.example.com/webhook.php'];

	protected function config(): array {
		$c = parent::config();
		$c['telegram'] = array_merge($c['telegram'], $this->telegram_config);
		return $c;
	}

	protected function form(): PlayerForm {
		return $this->app->player_form;
	}

	protected function say(Player $who, string $text): string {
		$this->http->reset();
		$this->dispatch($this->privateMessage($who, $text));
		return $this->http->lastText();
	}

	/**
		Signed initData as Telegram sends it for a Direct Mini App link with ?startapp=$start_param.
	*/
	protected function initWithParam(Player $who, string $start_param): string {
		return WebAppInitData::sign([
			'auth_date' => (string) $this->clock->now()->getTimestamp(),
			'start_param' => $start_param,
			'user' => (string) json_encode([
				'id' => $who->tg_user_id,
				'first_name' => $who->display_name,
				'username' => $who->username,
			], JSON_UNESCAPED_UNICODE),
		], (string) $this->config()['telegram']['token']);
	}

	/**
		The context token behind the web_app button the bot sends for /start pe_<id>.
	*/
	protected function tokenFromStart(Player $who, int $player_id): string {
		$this->say($who, '/start pe_' . $player_id);
		$markup = $this->http->sent('sendMessage')[0]['reply_markup']['inline_keyboard'][0][0] ?? [];
		self::assertStringContainsString('/webapp/player.html?ctx=', (string) ($markup['web_app']['url'] ?? ''));
		parse_str((string) parse_url((string) $markup['web_app']['url'], PHP_URL_QUERY), $q);
		return (string) $q['ctx'];
	}

	protected function activeRejection(callable $fn): string {
		try {
			$fn();
		} catch (ValidationError $e) {
			return $e->getMessage();
		}
		self::fail('expected a refusal');
	}

	// ---------------------------------------------------------------- /players rendering

	public function testListUsesOneNameAndLinksAndHidesLevelNextToNames(): void {
		$vasya = $this->player('vasya');
		$this->app->nicks->setOwn($vasya->id, 'ruskid');
		$this->rate($vasya, 5);
		$this->app->players->ensureByTelegram(777555, null, 'Без <ника>', $this->clock->now());
		$this->say($this->owner, '/addplayer Тень');
		$text = $this->say($this->player('petya'), '/players');

		self::assertStringContainsString("<blockquote><b>Танк (5)</b></blockquote>\n<a href=\"https://t.me/vasya\">ruskid</a>\n", $text);
		self::assertStringContainsString("\n<a href=\"https://t.me/petya\">@petya</a>\n", $text);
		self::assertStringContainsString("\n<a href=\"tg://user?id=777555\">Без &lt;ника&gt;</a>\n", $text);
		self::assertStringContainsString("\nТень\n", $text . "\n", 'no Telegram and no username: plain text');
		self::assertStringNotContainsString('lvl', $text);
		self::assertStringNotContainsString('·', $text, 'one name per player');
		self::assertStringContainsString('Как устроены уровни — /help_level', $text);
	}

	public function testOwnerGetsPencilsOnlyInPrivateDirectLink(): void {
		$this->telegram_config = ['webhook_url' => '', 'miniapp_short_name' => 'card'];
		$this->setUp();
		$vasya = $this->player('vasya');
		$this->app->nicks->setOwn($vasya->id, 'ruskid');

		$private = $this->say($this->owner, '/players');
		self::assertStringContainsString(
			'<a href="https://t.me/pop1_bot/card?startapp=p' . $vasya->id . '">✏️</a>',
			$private,
			'one tap: Direct Mini App link with the player id in startapp',
		);
		self::assertStringContainsString('✏️ — карточка игрока', $private);
	}

	public function testPencilFallsBackToTheTwoStepDeepLinkWithoutShortName(): void {
		$vasya = $this->player('vasya');
		$private = $this->say($this->owner, '/players');
		self::assertStringContainsString('<a href="https://t.me/pop1_bot?start=pe_' . $vasya->id . '">✏️</a>', $private);
	}

	public function testNoPencilsInGroupOrForOthers(): void {
		$this->telegram_config = ['webhook_url' => 'https://pop1.example.com/webhook.php', 'miniapp_short_name' => 'card'];
		$this->setUp();
		$vasya = $this->player('vasya');

		$this->http->reset();
		$this->dispatch($this->groupMessage($this->owner, '/players'));
		self::assertStringNotContainsString('✏️', $this->http->lastText(), 'the owner in a group: no pencils');
		self::assertStringNotContainsString('startapp', $this->http->lastText());

		self::assertStringNotContainsString('✏️', $this->say($vasya, '/players'), 'a regular user: no pencils');
		self::assertStringNotContainsString('✏️', $this->say($this->admin, '/players'), 'a chat admin: no pencils');
	}

	public function testNoPencilsWithoutAnyWayToOpenTheCard(): void {
		$this->telegram_config = ['webhook_url' => ''];
		$this->setUp();
		$this->player('vasya');
		self::assertStringNotContainsString('✏️', $this->say($this->owner, '/players'));
	}

	public function testEditLinkBuilders(): void {
		self::assertSame('https://t.me/pop1_bot?start=pe_7', $this->form()->editLink(7));
		$with_app = new PlayerForm(
			$this->app->webapp_contexts,
			$this->app->players,
			$this->app->player_admin,
			$this->app->access,
			$this->app->balances,
			$this->app->telegram,
			$this->clock,
			'1:T',
			'',
			'pop1_bot',
			'card',
		);
		self::assertSame('https://t.me/pop1_bot/card?startapp=p7', $with_app->editLink(7));
		self::assertSame('https://t.me/pop1_bot/card', \P1Bot\View\DeepLink::miniApp('pop1_bot', 'card'));
		self::assertSame('https://host/webapp/player.html?ctx=abc', \P1Bot\View\WebAppUrl::playerForm('https://host/webhook.php', 'abc'));
	}

	public function testDeletedPlayersAreHiddenFromTheList(): void {
		$vasya = $this->player('vasya');
		$this->app->nicks->setOwn($vasya->id, 'ruskid');
		$this->app->player_admin->delete($this->owner->id, $vasya->id);
		$text = $this->say($this->owner, '/players');
		self::assertStringNotContainsString('ruskid', $text);
		self::assertStringNotContainsString('vasya', $text);
	}

	// ---------------------------------------------------------------- opening the card (permissions)

	public function testStartDeepLinkGivesOnlyTheOwnerAButton(): void {
		$vasya = $this->player('vasya');
		$stranger = $this->player('stranger');

		$reply = $this->say($stranger, '/start pe_' . $vasya->id);
		self::assertSame('Это доступно только главному админу.', $reply);
		self::assertArrayNotHasKey('reply_markup', $this->http->sent('sendMessage')[0]);

		$token = $this->tokenFromStart($this->owner, $vasya->id);
		self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);
		self::assertStringContainsString('Карточка игрока <b>@vasya</b>', $this->http->lastText());

		self::assertSame(
			PlayerForm::GONE_TEXT,
			$this->say($this->owner, '/start pe_99999'),
		);
	}

	public function testBootstrapByContextTokenAndByStartParam(): void {
		$vasya = $this->player('vasya');
		$this->app->nicks->setOwn($vasya->id, 'ruskid');
		$this->rate($vasya, 5);
		$token = $this->tokenFromStart($this->owner, $vasya->id);

		$by_token = $this->form()->bootstrap($this->initDataFor($this->owner), $token);
		$by_param = $this->form()->bootstrap($this->initWithParam($this->owner, 'p' . $vasya->id), '');
		self::assertSame($by_token, $by_param);
		self::assertSame('ruskid', $by_token['player']['title']);
		self::assertSame(5, $by_token['player']['level']);
		self::assertSame('vasya', $by_token['player']['username']);
		self::assertTrue($by_token['player']['tg_bound']);
		self::assertSame([], $by_token['delete_blockers']);
	}

	public function testEveryApiCallRefusesNonOwners(): void {
		$vasya = $this->player('vasya');
		$stranger = $this->player('stranger');
		$token = $this->tokenFromStart($this->owner, $vasya->id);
		$stranger_init = $this->initWithParam($stranger, 'p' . $vasya->id);
		$calls = [
			'bootstrap' => fn () => $this->form()->bootstrap($stranger_init, ''),
			'search' => fn () => $this->form()->search($stranger_init, '', 'a'),
			'save' => fn () => $this->form()->save($stranger_init, '', ['ign' => 'Hacked']),
			'delete' => fn () => $this->form()->delete($stranger_init, ''),
			'merge' => fn () => $this->form()->merge($stranger_init, '', $this->owner->id),
			'token of the owner used by someone else' => fn () => $this->form()->bootstrap($this->initDataFor($stranger), $token),
			'chat admin' => fn () => $this->form()->delete($this->initWithParam($this->admin, 'p' . $vasya->id), ''),
		];
		foreach ($calls as $name => $call) {
			try {
				$call();
				self::fail($name . ': must be refused');
			} catch (WebAppFormError $e) {
				self::assertSame(WebAppFormError::FORBIDDEN, $e->reason, $name);
				self::assertSame('Это доступно только главному админу.', $e->getMessage(), $name);
			}
		}
		self::assertNotNull($this->app->players->find($vasya->id));
		self::assertFalse($this->app->players->find($vasya->id)->isDeleted());
		self::assertNull($this->app->players->find($vasya->id)->ign);
	}

	public function testForgedOrStaleLaunchDataIsRejected(): void {
		$vasya = $this->player('vasya');
		$init = $this->initWithParam($this->owner, 'p' . $vasya->id);
		// A different start_param under the same signature does not verify.
		$forged = str_replace('p' . $vasya->id, 'p' . $this->owner->id, $init);
		try {
			$this->form()->bootstrap($forged, '');
			self::fail('tampered initData accepted');
		} catch (ValidationError $e) {
			self::assertSame('Подпись Mini App неверна.', $e->getMessage());
		}
		// No start_param and no token: nothing to open.
		try {
			$this->form()->bootstrap($this->initDataFor($this->owner), '');
			self::fail('opened without a card');
		} catch (WebAppFormError $e) {
			self::assertSame(WebAppFormError::EXPIRED, $e->reason);
		}
		// Expired token.
		$token = $this->tokenFromStart($this->owner, $vasya->id);
		$this->clock->advance('+61 minutes');
		try {
			$this->form()->bootstrap($this->initDataFor($this->owner), $token);
			self::fail('expired token accepted');
		} catch (ValidationError $e) {
			self::assertTrue(true);
		}
	}

	// ---------------------------------------------------------------- edit

	public function testSaveNickLevelPreferredNameTogether(): void {
		$vasya = $this->player('vasya');
		$this->app->nicks->setOwn($vasya->id, 'ruskid');
		$init = $this->initWithParam($this->owner, 'p' . $vasya->id);

		$result = $this->form()->save($init, '', [
			'ign' => 'Ruskid Pro',
			'level' => 4,
			'username' => '@Vasya',
			'preferred_team_name' => '  Секта   Огня ',
		]);
		self::assertSame(['ok' => true, 'close' => true], $result);
		$after = $this->app->players->find($vasya->id);
		self::assertSame('Ruskid Pro', $after->ign);
		self::assertSame('vasya', $after->username, 'the same @username is not a change');
		self::assertSame($vasya->tg_user_id, $after->tg_user_id, 'still bound');
		self::assertSame(4, $this->app->balances->explicitLevel($this->app->balances->viewScaleId(), $vasya->id));
		self::assertSame('Секта Огня', $this->app->players->preferredTeamName($vasya->id));
		self::assertSame(
			1,
			(int) $this->db->fetchValue('SELECT COUNT(*) FROM player_ign_history WHERE player_id = :p AND ign = :i', ['p' => $vasya->id, 'i' => 'ruskid']),
			'the old nick goes to the history',
		);
	}

	public function testClearNickLevelAndPreferredName(): void {
		$vasya = $this->player('vasya');
		$this->app->nicks->setOwn($vasya->id, 'ruskid');
		$this->rate($vasya, 5);
		$this->app->players->setPreferredTeamName($vasya->id, 'Волки');

		$this->app->player_admin->save($this->owner->id, $vasya->id, ['ign' => '', 'level' => null, 'preferred_team_name' => '']);
		$after = $this->app->players->find($vasya->id);
		self::assertNull($after->ign);
		self::assertNull($after->ign_norm);
		self::assertNull($this->app->balances->explicitLevel($this->app->balances->viewScaleId(), $vasya->id));
		self::assertNull($this->app->players->preferredTeamName($vasya->id));
		self::assertNull($this->app->players->findByIgnNorm('ruskid'), 'the nick is free again');
		self::assertStringContainsString('@vasya', $this->say($this->owner, '/players'), 'the list falls back to @username');
	}

	public function testNickMustBeUnique(): void {
		$vasya = $this->player('vasya');
		$petya = $this->player('petya');
		$this->app->nicks->setOwn($petya->id, 'Zoloto');
		try {
			$this->form()->save($this->initWithParam($this->owner, 'p' . $vasya->id), '', ['ign' => 'zoloto', 'level' => 6]);
			self::fail('a taken nick was accepted');
		} catch (WebAppFormError $e) {
			self::assertSame('ign', $e->field);
			self::assertStringContainsString('уже у игрока', $e->getMessage());
			self::assertArrayHasKey('ign', $e->fields);
		}
		self::assertNull($this->app->players->find($vasya->id)->ign);
		self::assertNull($this->app->balances->explicitLevel($this->app->balances->viewScaleId(), $vasya->id), 'nothing is saved when a field is wrong');
		self::assertSame($petya->id, $this->app->players->findByIgnNorm('zoloto')->id, 'the other player keeps the nick');
	}

	public function testInvalidFieldsAreReportedTogether(): void {
		$vasya = $this->player('vasya');
		try {
			$this->app->player_admin->save($this->owner->id, $vasya->id, [
				'ign' => 'a',
				'level' => 9,
				'preferred_team_name' => str_repeat('я', 65),
			]);
			self::fail('invalid fields accepted');
		} catch (\P1Bot\Domain\Exception\FieldError $e) {
			self::assertSame(['ign', 'level', 'preferred_team_name'], array_keys($e->fields));
		}
	}

	public function testCardMustStayFindable(): void {
		$offline = $this->app->nicks->addPlayer($this->owner->id, null, 'Тень')['player'];
		try {
			$this->app->player_admin->save($this->owner->id, $offline->id, ['ign' => '']);
			self::fail('a card without any identity was accepted');
		} catch (\P1Bot\Domain\Exception\FieldError $e) {
			self::assertArrayHasKey('ign', $e->fields);
		}
		self::assertSame('Тень', $this->app->players->find($offline->id)->ign);
	}

	public function testUsernameOfAnOfflineCardIsFreelyEditable(): void {
		$offline = $this->app->nicks->addPlayer($this->owner->id, 'wrong_guy', 'Тень')['player'];
		$this->app->player_admin->save($this->owner->id, $offline->id, ['username' => '@Right_Guy']);
		self::assertSame('right_guy', $this->app->players->find($offline->id)->username);
		self::assertNull($this->app->players->findByUsername('wrong_guy'));

		// The person who writes with that @username claims the card.
		$claimed = $this->app->players->ensureByTelegram(555111, 'right_guy', 'Right', $this->clock->now());
		self::assertSame($offline->id, $claimed->id);
		self::assertSame('Тень', $claimed->ign);

		// Clearing the @username of a card that keeps a nick is fine; a card with nothing else is not.
		$both = $this->app->nicks->addPlayer($this->owner->id, 'both_card', 'Двойник')['player'];
		$this->app->player_admin->save($this->owner->id, $both->id, ['username' => '']);
		self::assertNull($this->app->players->find($both->id)->username);
		$bare = $this->app->nicks->addPlayer($this->owner->id, 'bare_card', null)['player'];
		try {
			$this->app->player_admin->save($this->owner->id, $bare->id, ['username' => '']);
			self::fail('a card without any identity was accepted');
		} catch (\P1Bot\Domain\Exception\FieldError $e) {
			self::assertArrayHasKey('username', $e->fields);
		}
	}

	public function testChangingUsernameOfABoundCardNeedsConfirmationAndUnbinds(): void {
		$vasya = $this->player('vasya');
		$this->app->nicks->setOwn($vasya->id, 'ruskid');
		$this->rate($vasya, 5);
		$t = $this->openTournament(3, null);
		$this->app->teams->createTeam($t->id, $vasya->id, 'Секта', []);
		$init = $this->initWithParam($this->owner, 'p' . $vasya->id);

		try {
			$this->form()->save($init, '', ['username' => '@real_ruskid']);
			self::fail('rebinding without confirmation');
		} catch (WebAppFormError $e) {
			self::assertSame('confirm_unbind', $e->field);
		}
		self::assertSame('vasya', $this->app->players->find($vasya->id)->username);
		self::assertSame($vasya->tg_user_id, $this->app->players->find($vasya->id)->tg_user_id);

		$this->form()->save($init, '', ['username' => '@real_ruskid', 'confirm_unbind' => true]);
		$after = $this->app->players->find($vasya->id);
		self::assertSame('real_ruskid', $after->username);
		self::assertNull($after->tg_user_id, 'the old Telegram account is detached');
		self::assertSame('ruskid', $after->ign, 'nick, level and teams stay on the card');
		self::assertSame(5, $this->app->balances->explicitLevel($this->app->balances->viewScaleId(), $vasya->id));
		self::assertNotNull($this->app->teams->teamOf($t->id, $vasya->id));
		self::assertFalse($this->app->players->dmOk($vasya->id));

		// The old account writes again: a fresh player, not the card.
		$again = $this->app->players->ensureByTelegram((int) $vasya->tg_user_id, 'vasya', 'Vasya', $this->clock->now());
		self::assertNotSame($vasya->id, $again->id);
		// The real owner of the new @username claims the card.
		$real = $this->app->players->ensureByTelegram(424242, 'real_ruskid', 'Real', $this->clock->now());
		self::assertSame($vasya->id, $real->id);
		self::assertSame('ruskid', $real->ign);
	}

	public function testUsernameRules(): void {
		$vasya = $this->player('vasya');
		$petya = $this->player('petya');
		$admin = $this->app->player_admin;
		$cases = [
			[['username' => '@bad'], 'Username: 5–32'],
			[['username' => '@petya', 'confirm_unbind' => true], 'уже у игрока'],
			[['username' => ''], 'подтянется из него сам'],
		];
		foreach ($cases as [$form, $needle]) {
			try {
				$admin->save($this->owner->id, $vasya->id, $form);
				self::fail('accepted ' . json_encode($form));
			} catch (\P1Bot\Domain\Exception\FieldError $e) {
				self::assertStringContainsString($needle, $e->getMessage());
			}
		}
		self::assertSame('petya', $this->app->players->find($petya->id)->username);
		// The owner's own card never changes its @username.
		try {
			$admin->save($this->owner->id, $this->owner->id, ['username' => '@another_one', 'confirm_unbind' => true]);
			self::fail('the owner card was re-pointed');
		} catch (\P1Bot\Domain\Exception\FieldError $e) {
			self::assertStringContainsString('главного админа', $e->getMessage());
		}
		self::assertSame(self::OWNER_TG_ID, $this->app->players->find($this->owner->id)->tg_user_id);
	}

	public function testSaveDomainRefusesNonOwner(): void {
		$vasya = $this->player('vasya');
		$this->expectException(PermissionDenied::class);
		$this->app->player_admin->save($this->admin->id, $vasya->id, ['ign' => 'Hacked']);
	}

	public function testSearchForMergeExcludesTheCardAndDeletedAndExplainsProblems(): void {
		$vasya = $this->player('vasya');
		$petya = $this->player('petya');
		$gone = $this->player('vasya_gone');
		$this->app->player_admin->delete($this->owner->id, $gone->id);
		$result = $this->form()->search($this->initWithParam($this->owner, 'p' . $vasya->id), '', 'vasya');
		self::assertSame([], $result['players'], 'neither the card itself nor deleted players');
		$result = $this->form()->search($this->initWithParam($this->owner, 'p' . $vasya->id), '', 'petya');
		self::assertSame([$petya->id], array_column($result['players'], 'id'));
		self::assertNotNull($result['players'][0]['problem'], 'two bound accounts cannot be merged');
	}

	// ---------------------------------------------------------------- delete

	public function testDeleteIsBlockedWhileThePlayerIsStillInPlay(): void {
		$cap = $this->player('cap');
		$mate = $this->player('mate');
		$solo = $this->player('solo');
		$organizer = $this->player('organizer');
		$t = $this->openTournament(3, null);
		$this->app->teams->createTeam($t->id, $cap->id, 'Волки', [$mate->id]);
		$this->app->teams->joinSolo($t->id, $solo->id);
		$org_t = $this->app->tournament_repo->create([
			'chat_id' => self::CHAT_ID,
			'organizer_id' => $organizer->id,
			'title' => 'Мой кубок',
			'starts_at' => \P1Bot\Domain\MoscowTime::parse('2026-11-30 19:00'),
			'team_size' => 3,
			'balance_id' => null,
			'reference_team' => null,
			'max_points' => null,
			'max_players' => 24,
			'status' => TournamentStatus::DRAFT,
		], $this->clock->now());

		$admin = $this->app->player_admin;
		self::assertSame(
			['в команде «Волки», турнир №' . $t->id . ' «Субботний кубок»'],
			array_values(array_filter($admin->deleteBlockers($mate->id), static fn (string $s): bool => str_starts_with($s, 'в команде'))),
		);
		self::assertContains('капитан команды «Волки», турнир №' . $t->id . ' «Субботний кубок»', $admin->deleteBlockers($cap->id));
		self::assertContains('записан без команды, турнир №' . $t->id . ' «Субботний кубок»', $admin->deleteBlockers($solo->id));
		self::assertSame(['организатор турнира №' . $org_t->id . ' «Мой кубок»'], $admin->deleteBlockers($organizer->id));

		foreach ([$cap, $mate, $solo, $organizer] as $blocked) {
			$message = $this->activeRejection(fn () => $this->form()->delete($this->initWithParam($this->owner, 'p' . $blocked->id), ''));
			self::assertStringContainsString('Удалить нельзя, пока игрок ещё играет', $message);
			self::assertFalse($this->app->players->find($blocked->id)->isDeleted());
		}
		self::assertSame($admin->deleteBlockers($mate->id), $this->form()->bootstrap($this->initWithParam($this->owner, 'p' . $mate->id), '')['delete_blockers']);

		// Once the tournaments are over, deleting works.
		$this->app->tournament_repo->update($t->id, ['status' => TournamentStatus::FINISHED]);
		$this->app->tournament_repo->update($org_t->id, ['status' => TournamentStatus::CANCELLED]);
		foreach ([$cap, $mate, $solo, $organizer] as $free) {
			self::assertSame([], $admin->deleteBlockers($free->id));
		}
	}

	public function testOwnerCannotBeDeleted(): void {
		$message = $this->activeRejection(fn () => $this->app->player_admin->delete($this->owner->id, $this->owner->id));
		self::assertStringContainsString('главный админ', $message);
		self::assertFalse($this->app->players->find($this->owner->id)->isDeleted());
	}

	public function testSoftDeleteReleasesIdentityHidesAndKeepsHistory(): void {
		$vasya = $this->player('vasya');
		$this->app->nicks->setOwn($vasya->id, 'ruskid');
		$this->rate($vasya, 5);
		$past = $this->openTournament(3, null);
		$other = $this->player('other');
		$this->app->teams->createTeam($past->id, $vasya->id, 'Старички', [$other->id]);
		$this->app->tournament_repo->update($past->id, ['status' => TournamentStatus::FINISHED]);

		$this->form()->delete($this->initWithParam($this->owner, 'p' . $vasya->id), '');

		$gone = $this->app->players->find($vasya->id);
		self::assertTrue($gone->isDeleted());
		self::assertNull($gone->tg_user_id);
		self::assertNull($gone->username);
		self::assertNull($gone->ign);
		self::assertSame('', $gone->display_name);
		self::assertSame('удалённый игрок', $gone->plainLabel());
		self::assertSame('удалённый игрок', $gone->lineupLabel());
		self::assertSame('удалённый игрок', $gone->mention());

		// Hidden everywhere.
		self::assertNull($this->app->players->findByIgnNorm('ruskid'));
		self::assertNull($this->app->players->findByUsername('vasya'));
		self::assertNull($this->app->players->findByTelegramId((int) $vasya->tg_user_id));
		self::assertNotContains($vasya->id, array_map(static fn (Player $p): int => $p->id, $this->app->players->listAllKnown()));
		self::assertNotContains($vasya->id, array_map(static fn (Player $p): int => $p->id, $this->app->players->listKnown(50)));
		self::assertNotContains($vasya->id, array_map(static fn (Player $p): int => $p->id, $this->app->players->listPlaceholders()));
		self::assertSame([], $this->app->players->searchPartial('vasya'));
		self::assertSame([], $this->app->players->searchPartial('ruskid'));
		self::assertSame([], $this->app->players->fuzzySuggest('ruskid'));
		self::assertStringNotContainsString('ruskid', $this->say($this->owner, '/players'));

		// History keeps the team and shows «удалённый игрок».
		$info = implode("\n", $this->app->announcer->infoMessages($this->app->tournament_repo->find($past->id)));
		self::assertStringContainsString('удалённый игрок', $info);
		self::assertNotNull($this->app->teams->teamOf($past->id, $vasya->id));
		self::assertStringContainsString('Старички', $info);

		// The nick and @username are free again; the person coming back is a new player.
		$this->app->nicks->addPlayer($this->owner->id, null, 'ruskid');
		$new = $this->app->players->findByIgnNorm('ruskid');
		self::assertNotSame($vasya->id, $new->id);
		$back = $this->app->players->ensureByTelegram((int) $vasya->tg_user_id, 'vasya', 'Vasya', $this->clock->now());
		self::assertNotSame($vasya->id, $back->id);
		self::assertNull($this->app->balances->explicitLevel($this->app->balances->viewScaleId(), $back->id), 'a fresh player has no level');
	}

	public function testDeletedCardCannotBeOpenedAgain(): void {
		$vasya = $this->player('vasya');
		$this->app->player_admin->delete($this->owner->id, $vasya->id);
		try {
			$this->form()->bootstrap($this->initWithParam($this->owner, 'p' . $vasya->id), '');
			self::fail('a deleted card was opened');
		} catch (WebAppFormError $e) {
			self::assertSame(PlayerForm::GONE_TEXT, $e->getMessage());
		}
		self::assertSame(PlayerForm::GONE_TEXT, $this->say($this->owner, '/start pe_' . $vasya->id));
	}

	// ---------------------------------------------------------------- merge

	public function testMergeMovesEverythingOntoTheSurvivor(): void {
		$real = $this->player('realguy');
		$this->app->nicks->setOwn($real->id, 'Zoloto');
		$this->rate($real, 4);
		// The duplicate: a hand-added card with a nick, a level, an old nick and a lot of history.
		$dupe = $this->app->nicks->addPlayer($this->owner->id, 'dupe_guy', 'Zoloto Old')['player'];
		$this->app->nicks->assignByOwner($this->owner->id, $dupe->id, 'Zoloto Older');
		$this->app->nicks->assignByOwner($this->owner->id, $dupe->id, 'Zoloto Old');
		$this->app->players->setPreferredTeamName($dupe->id, 'Волки');
		$this->rate($dupe, 6);

		$past = $this->openTournament(3, null);
		$mate = $this->player('mate');
		$old_team = $this->app->teams->createTeam($past->id, $mate->id, 'Старички', [$dupe->id]);
		// An offline captain is not possible through the bot any more, but old data has it.
		$this->db->run('UPDATE teams SET captain_id = :c WHERE id = :t', ['c' => $dupe->id, 't' => $old_team->team->id]);
		$this->app->tournament_repo->update($past->id, ['status' => TournamentStatus::FINISHED]);
		$open = $this->openTournament(3, null);
		$this->app->teams->joinSolo($open->id, $dupe->id);
		$org_t = $this->app->tournament_repo->create([
			'chat_id' => self::CHAT_ID,
			'organizer_id' => $dupe->id,
			'title' => 'Кубок дубля',
			'starts_at' => \P1Bot\Domain\MoscowTime::parse('2026-11-30 19:00'),
			'team_size' => 3,
			'balance_id' => null,
			'reference_team' => null,
			'max_points' => null,
			'max_players' => 24,
			'status' => TournamentStatus::DRAFT,
		], $this->clock->now());

		$this->form()->merge($this->initWithParam($this->owner, 'p' . $dupe->id), '', $real->id);

		$survivor = $this->app->players->find($real->id);
		self::assertFalse($survivor->isDeleted());
		self::assertSame('Zoloto', $survivor->ign, 'the survivor keeps its own nick');
		self::assertSame($real->tg_user_id, $survivor->tg_user_id);
		self::assertSame(4, $this->app->balances->explicitLevel($this->app->balances->viewScaleId(), $real->id), 'the survivor level wins');
		self::assertSame('Волки', $this->app->players->preferredTeamName($real->id), 'filled from the duplicate');
		$history = array_column($this->db->fetchAll('SELECT ign FROM player_ign_history WHERE player_id = :p ORDER BY id', ['p' => $real->id]), 'ign');
		self::assertContains('Zoloto Older', $history, 'nick history moves over');
		self::assertContains('Zoloto Old', $history, 'the duplicate nick goes to the history of the survivor');

		self::assertSame($real->id, $this->app->teams->teamOf($past->id, $real->id)?->team->captain_id, 'captaincy moves');
		self::assertNull($this->app->teams->teamOf($past->id, $dupe->id));
		self::assertNotNull($this->app->solo_repo->find($open->id, $real->id), 'solo sign-up moves');
		self::assertNull($this->app->solo_repo->find($open->id, $dupe->id));
		self::assertSame($real->id, $this->app->tournament_repo->find($org_t->id)->organizer_id, 'organizer moves');

		$gone = $this->app->players->find($dupe->id);
		self::assertTrue($gone->isDeleted());
		self::assertNull($gone->ign);
		self::assertNull($gone->username);
		self::assertNull($this->app->balances->explicitLevel($this->app->balances->viewScaleId(), $dupe->id));
		self::assertSame(
			0,
			(int) $this->db->fetchValue('SELECT COUNT(*) FROM player_ign_history WHERE player_id = :p', ['p' => $dupe->id]),
		);
	}

	public function testMergeGivesTheSurvivorMissingIdentityAndTelegram(): void {
		// Survivor: a bare offline card with a nick; duplicate: the bound Telegram account.
		$survivor = $this->app->nicks->addPlayer($this->owner->id, null, 'Тень')['player'];
		$dupe = $this->player('vasya');
		$this->rate($dupe, 5);
		$this->app->players->markDmStarted($dupe->id, $this->clock->now());

		$this->app->player_admin->merge($this->owner->id, $dupe->id, $survivor->id);
		$after = $this->app->players->find($survivor->id);
		self::assertSame('Тень', $after->ign);
		self::assertSame('vasya', $after->username);
		self::assertSame($dupe->tg_user_id, $after->tg_user_id, 'the Telegram binding follows the merge');
		self::assertSame('Тень', $after->display_name, 'the name is refreshed from Telegram on the next message');
		self::assertTrue($this->app->players->dmOk($survivor->id));
		self::assertSame(5, $this->app->balances->explicitLevel($this->app->balances->viewScaleId(), $survivor->id), 'level of the duplicate when the survivor has none');
		self::assertSame($survivor->id, $this->app->players->findByTelegramId((int) $dupe->tg_user_id)->id);
		self::assertSame('vasya', $this->app->players->findByUsername('vasya')->username);
	}

	public function testMergeRefusals(): void {
		$a = $this->player('guy_a');
		$b = $this->player('guy_b');
		$admin = $this->app->player_admin;

		self::assertStringContainsString(
			'разные люди',
			$this->activeRejection(fn () => $admin->merge($this->owner->id, $a->id, $b->id)),
		);
		self::assertStringContainsString(
			'Карточку главного админа',
			$this->activeRejection(fn () => $admin->merge($this->owner->id, $this->owner->id, $a->id)),
		);
		self::assertStringContainsString(
			'сам в себя',
			$this->activeRejection(fn () => $admin->merge($this->owner->id, $a->id, $a->id)),
		);

		// Both in one tournament: refused, nothing changes.
		$offline = $this->app->nicks->addPlayer($this->owner->id, null, 'Тень')['player'];
		$t = $this->openTournament(3, null);
		$this->app->teams->createTeam($t->id, $a->id, 'Волки', []);
		$this->app->teams->joinSolo($t->id, $offline->id);
		$message = $this->activeRejection(fn () => $admin->merge($this->owner->id, $offline->id, $a->id));
		self::assertStringContainsString('Оба уже есть в турнире №' . $t->id . ' «Субботний кубок»', $message);
		self::assertFalse($this->app->players->find($offline->id)->isDeleted());
		self::assertNotNull($this->app->solo_repo->find($t->id, $offline->id));

		// The owner can be the survivor.
		$offline2 = $this->app->nicks->addPlayer($this->owner->id, null, 'Призрак')['player'];
		$admin->merge($this->owner->id, $offline2->id, $this->owner->id);
		self::assertSame('Призрак', $this->app->players->find($this->owner->id)->ign);
	}

	public function testMergeRequiresTheOwner(): void {
		$a = $this->player('guy_a');
		$offline = $this->app->nicks->addPlayer($this->owner->id, null, 'Тень')['player'];
		$this->expectException(PermissionDenied::class);
		$this->app->player_admin->merge($this->admin->id, $offline->id, $a->id);
	}

	// ---------------------------------------------------------------- static assets

	public function testWebappPagesExistAndAreWired(): void {
		$dir = dirname(__DIR__, 2) . '/public/webapp';
		foreach (['app.html', 'app.js', 'player.html', 'player.js', 'player.css'] as $file) {
			self::assertFileExists($dir . '/' . $file);
		}
		self::assertStringContainsString("page: 'player.html'", (string) file_get_contents($dir . '/app.js'));
		self::assertStringContainsString('start_param', (string) file_get_contents($dir . '/app.js'));
		$html = (string) file_get_contents($dir . '/player.html');
		self::assertStringContainsString('create.css', $html, 'the theme variables come from the shared css');
		$js = (string) file_get_contents($dir . '/player.js');
		foreach (['player_bootstrap', 'player_save', 'player_delete', 'player_merge', 'player_search'] as $op) {
			self::assertStringContainsString("'" . $op . "'", $js);
		}
		$api = (string) file_get_contents(dirname(__DIR__, 2) . '/public/webapp_api.php');
		foreach (['player_bootstrap', 'player_save', 'player_delete', 'player_merge', 'player_search'] as $op) {
			self::assertStringContainsString("'" . $op . "'", $api);
		}
		self::assertStringContainsString("!str_starts_with(\$op, 'player_')", $api, 'the card may come without a context token');
	}
}
