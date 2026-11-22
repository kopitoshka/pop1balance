<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\App\PlayerForm;
use P1Bot\App\SuperAdmin;
use P1Bot\App\WebAppFormError;
use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Domain\Model\Player;
use P1Bot\Telegram\WebAppInitData;
use P1Bot\Tests\Support\IntegrationTestCase;

/**
	/su: the superadmin right, the per-player mode, grant / revoke / list, what the mode opens,
	visibility (help, menu, unknown command), the card checkbox and the su log.
*/
class SuperAdminTest extends IntegrationTestCase {
	protected const UNKNOWN = 'Такой команды я не знаю 🤔 Подсказка: /help';

	protected string $log_dir = '';

	protected function setUp(): void {
		$this->log_dir = sys_get_temp_dir() . '/p1bot-su-' . bin2hex(random_bytes(4));
		parent::setUp();
	}

	protected function tearDown(): void {
		foreach (glob($this->log_dir . '/*') ?: [] as $file) {
			@unlink($file);
		}
		@rmdir($this->log_dir);
	}

	protected function config(): array {
		$c = parent::config();
		$c['paths'] = ['logs' => $this->log_dir];
		$c['dialog_log'] = false;
		$c['telegram'] = array_merge($c['telegram'], ['webhook_url' => 'https://pop1.example.com/webhook.php']);
		return $c;
	}

	protected function say(Player $who, string $text): string {
		$this->http->reset();
		$this->dispatch($this->privateMessage($who, $text));
		return $this->http->lastText();
	}

	protected function sayInGroup(Player $who, string $text): string {
		$this->http->reset();
		$this->dispatch($this->groupMessage($who, $text));
		return $this->http->lastText();
	}

	protected function fresh(Player $player): Player {
		return $this->app->players->find($player->id) ?? $player;
	}

	/**
		A superadmin with the mode on (as after /su grant and /su on).
	*/
	protected function superadmin(string $username, bool $mode_on = true): Player {
		$player = $this->player($username);
		$this->app->players->setSuRight($player->id, true);
		if ($mode_on) {
			$this->app->players->setSuMode($player->id, true);
		}
		return $this->fresh($player);
	}

	protected function suLog(): string {
		$file = $this->log_dir . '/su.log';
		return is_file($file) ? (string) file_get_contents($file) : '';
	}

	/**
		@return list<string>
	*/
	protected function suLines(): array {
		return array_values(array_filter(explode("\n", $this->suLog())));
	}

	protected function cardInit(Player $who, Player $card): string {
		return WebAppInitData::sign([
			'auth_date' => (string) $this->clock->now()->getTimestamp(),
			'start_param' => PlayerForm::START_PARAM_PREFIX . $card->id,
			'user' => (string) json_encode([
				'id' => $who->tg_user_id,
				'first_name' => $who->display_name,
				'username' => $who->username,
			], JSON_UNESCAPED_UNICODE),
		], (string) $this->config()['telegram']['token']);
	}

	/**
		@return list<array<string, mixed>>
	*/
	protected function menusFor(Player $player, string $method = 'setMyCommands'): array {
		$found = [];
		foreach ($this->http->sent($method) as $request) {
			if (($request['scope']['chat_id'] ?? null) === $player->tg_user_id) {
				$found[] = $request;
			}
		}
		return $found;
	}

	/**
		@param array<string, mixed> $request
		@return list<string>
	*/
	protected static function commandNames(array $request): array {
		return array_map(static fn (array $c): string => $c['command'], $request['commands']);
	}

	// ---- the bot owner

	public function testOwnerModeIsOnByDefaultAndStatusListsEverything(): void {
		self::assertTrue($this->app->access->isSuper($this->owner->id), 'today\'s behavior is unchanged after deploy');
		$text = $this->say($this->owner, '/su');
		self::assertStringContainsString('Режим суперадмина: включён.', $text);
		self::assertStringContainsString('/su on', $text);
		self::assertStringContainsString('/su off', $text);
		self::assertStringContainsString('/su grant @ник', $text);
		self::assertStringContainsString('/su revoke @ник', $text);
		self::assertStringContainsString('/su list', $text);
		self::assertSame('', $this->suLog(), 'the status is not logged');
	}

	public function testOwnerCanSwitchHisOwnModeOffAndOnAgain(): void {
		self::assertStringContainsString('Выключила режим суперадмина', $this->say($this->owner, '/su off'));
		self::assertFalse($this->app->access->isSuper($this->owner->id));
		self::assertStringContainsString('Режим суперадмина: выключен.', $this->say($this->owner, '/su'));
		self::assertStringContainsString('уже выключен', $this->say($this->owner, '/su off'));

		self::assertStringContainsString('Включила режим суперадмина', $this->say($this->owner, '/su on'));
		self::assertTrue($this->app->access->isSuper($this->owner->id));
		self::assertStringContainsString('уже включён', $this->say($this->owner, '/su on'));
	}

	public function testOwnerWithModeOffSeesTheBotAsAPlayer(): void {
		$vasya = $this->player('vasya');
		$this->say($this->owner, '/su off');

		self::assertStringContainsString('Уровни игроков меняет только главный админ', $this->say($this->owner, '/level @vasya 4'));
		self::assertNull($this->app->balances->explicitLevel($this->app->balances->viewScaleId(), $vasya->id));
		self::assertStringNotContainsString('Только для главного админа', $this->say($this->owner, '/help'));
		$this->expectException(PermissionDenied::class);
		$this->app->access->assertSuper($this->owner->id);
	}

	public function testOwnerGrantsRevokesAndListsEvenWithTheModeOff(): void {
		$vasya = $this->player('vasya');
		$this->say($this->owner, '/su off');

		$text = $this->say($this->owner, '/su grant @vasya');
		self::assertStringContainsString('Дала право суперадмина игроку', $text);
		self::assertTrue($this->app->access->hasSuRight($vasya->id));

		$list = $this->say($this->owner, '/su list');
		self::assertStringContainsString('Суперадмины:', $list);
		self::assertStringContainsString('@vasya', $list);
		self::assertStringContainsString('главный админ', $list);

		self::assertStringContainsString('Забрала право суперадмина у', $this->say($this->owner, '/su revoke @vasya'));
		self::assertFalse($this->app->access->hasSuRight($vasya->id));
		self::assertFalse($this->app->access->isSuper($this->owner->id), 'his own mode stayed off the whole time');
	}

	public function testOwnerRightCannotBeRevokedAndIsAlwaysThere(): void {
		self::assertStringContainsString('Право главного админа забрать нельзя.', $this->say($this->owner, '/su revoke @andrey'));
		self::assertStringContainsString('У главного админа это право есть всегда.', $this->say($this->owner, '/su grant @andrey'));
		self::assertTrue($this->app->access->hasSuRight($this->owner->id));
		self::assertSame([], $this->suLines());
	}

	public function testListWithoutAnyoneButTheOwnerAndWithTheirMode(): void {
		$list = $this->say($this->owner, '/su list');
		self::assertStringContainsString('режим включён, главный админ', $list);
		$this->superadmin('vasya', false);
		$this->superadmin('petya', true);
		$list = $this->say($this->owner, '/su list');
		self::assertMatchesRegularExpression('/@vasya.*режим выключен/u', $list);
		self::assertMatchesRegularExpression('/@petya.*режим включён/u', $list);
	}

	// ---- a player with the right

	public function testRightHolderStartsWithTheModeOffAndSeesThePlayerView(): void {
		$vasya = $this->player('vasya');
		$this->say($this->owner, '/su grant @vasya');
		$vasya = $this->fresh($vasya);

		self::assertTrue($vasya->can_su);
		self::assertFalse($this->app->access->isSuper($vasya->id), 'the right does not switch the mode on');
		self::assertStringContainsString('Режим суперадмина: выключен.', $this->say($vasya, '/su'));
	}

	public function testRightHolderWithTheModeOffCannotGrantRevokeOrList(): void {
		$vasya = $this->superadmin('vasya', false);
		$petya = $this->player('petya');

		foreach (['/su grant @petya', '/su revoke @petya', '/su list'] as $command) {
			self::assertSame(SuperAdmin::MODE_OFF_FIRST, $this->say($vasya, $command), $command);
		}
		self::assertFalse($this->app->access->hasSuRight($petya->id));
		self::assertStringNotContainsString('/su grant', $this->say($vasya, '/su'), 'the hint is not offered either');
		self::assertSame([], $this->suLines());
	}

	public function testRightHolderWithTheModeOnGrantsAndRevokes(): void {
		$vasya = $this->superadmin('vasya', false);
		$petya = $this->player('petya');

		self::assertStringContainsString('Включила режим суперадмина', $this->say($vasya, '/su on'));
		self::assertStringContainsString('/su grant @ник', $this->say($vasya, '/su'));
		self::assertStringContainsString('Дала право суперадмина', $this->say($vasya, '/su grant @petya'));
		self::assertTrue($this->app->access->hasSuRight($petya->id));
		self::assertStringContainsString('@petya', $this->say($vasya, '/su list'));
		self::assertStringContainsString('Забрала право суперадмина у', $this->say($vasya, '/su revoke @petya'));
		self::assertFalse($this->app->access->hasSuRight($petya->id));
	}

	public function testTheRightOfTheOwnerCannotBeTakenByASuperadmin(): void {
		$vasya = $this->superadmin('vasya');
		self::assertSame(SuperAdmin::OWNER_KEEPS, $this->say($vasya, '/su revoke @andrey'));
		self::assertTrue($this->app->access->hasSuRight($this->owner->id));
	}

	public function testModeOnGivesEveryOwnerAbility(): void {
		$vasya = $this->superadmin('vasya', false);
		$petya = $this->player('petya');
		$scale = $this->app->balances->viewScaleId();

		self::assertStringContainsString('Уровни игроков меняет только главный админ', $this->say($vasya, '/level @petya 4'));
		self::assertNull($this->app->balances->explicitLevel($scale, $petya->id));

		$this->say($vasya, '/su on');
		self::assertTrue($this->app->access->isSuper($vasya->id));
		$this->say($vasya, '/level @petya 4');
		self::assertSame(4, $this->app->balances->explicitLevel($scale, $petya->id), 'levels, on the owner\'s shared scale');
		self::assertStringContainsString('Только для главного админа', $this->say($vasya, '/help'));
		self::assertStringContainsString('/players_import', $this->say($vasya, '/help'));

		$card = $this->app->player_form->bootstrap($this->cardInit($vasya, $petya), '');
		self::assertSame($petya->id, $card['player']['id'], 'the player card opens');
		self::assertTrue($this->app->access->canManageTournament($this->openTournament(), $vasya->id), 'any tournament');

		$this->say($vasya, '/su off');
		self::assertFalse($this->app->access->isSuper($vasya->id));
		try {
			$this->app->player_form->bootstrap($this->cardInit($vasya, $petya), '');
			self::fail('the card is closed again');
		} catch (WebAppFormError $e) {
			self::assertSame(WebAppFormError::FORBIDDEN, $e->reason);
		}
		self::assertStringContainsString('Уровни игроков меняет только главный админ', $this->say($vasya, '/level @petya 5'));
	}

	public function testTheModeSurvivesAcrossMessagesAndHasNoExpiry(): void {
		$vasya = $this->superadmin('vasya', false);
		$this->say($vasya, '/su on');
		$this->clock->advance('+30 days');
		self::assertTrue($this->app->access->isSuper($vasya->id));
		self::assertSame(1, (int) $this->db->fetchValue('SELECT su_mode FROM players WHERE id = :i', ['i' => $vasya->id]));
	}

	public function testRevokeTurnsTheModeOffAndRegrantDoesNotTurnItBackOn(): void {
		$vasya = $this->superadmin('vasya');
		self::assertTrue($this->app->access->isSuper($vasya->id));

		$this->say($this->owner, '/su revoke @vasya');
		$row = $this->fresh($vasya);
		self::assertFalse($row->can_su);
		self::assertFalse($row->su_mode, 'the stored mode is off');
		self::assertFalse($this->app->access->isSuper($vasya->id));

		$this->say($this->owner, '/su grant @vasya');
		self::assertTrue($this->app->access->hasSuRight($vasya->id));
		self::assertFalse($this->app->access->isSuper($vasya->id), 'back with the right, but the mode is off');
	}

	public function testGrantAndRevokeTwiceAreHarmlessAndNotLogged(): void {
		$this->player('vasya');
		$this->say($this->owner, '/su grant @vasya');
		self::assertStringContainsString('право суперадмина уже есть', $this->say($this->owner, '/su grant @vasya'));
		$this->say($this->owner, '/su revoke @vasya');
		self::assertStringContainsString('и так нет права суперадмина', $this->say($this->owner, '/su revoke @vasya'));
		self::assertCount(2, $this->suLines());
	}

	public function testWrongParameterAndMissingTarget(): void {
		$vasya = $this->superadmin('vasya');
		self::assertSame('Не поняла параметр. Можно так: /su on | off | grant @ник | revoke @ник | list.', $this->say($vasya, '/su wat'));
		self::assertStringContainsString('Укажи игрока', $this->say($vasya, '/su grant'));
		self::assertStringContainsString('Не нашла такого игрока', $this->say($vasya, '/su grant @nobodyhere'));
		$plain = $this->superadmin('petya', false);
		self::assertSame('Не поняла параметр. Можно так: /su on | off.', $this->say($plain, '/su wat'));
	}

	public function testGrantNeedsATelegramAccount(): void {
		$this->insertPlaceholder('ghost');
		$text = $this->say($this->owner, '/su grant @ghost');
		self::assertStringContainsString('нет Telegram', $text);
		self::assertSame([], $this->suLines());
	}

	public function testGrantByReplyToAMessage(): void {
		$vasya = $this->player('vasya');
		$this->http->reset();
		$this->dispatch($this->groupMessage($this->owner, '/su grant', ['reply_to_message' => [
			'message_id' => 5,
			'chat' => ['id' => self::CHAT_ID, 'type' => 'supergroup', 'title' => 'P1 турниры'],
			'from' => ['id' => $this->tgIdOf($vasya), 'is_bot' => false, 'first_name' => 'Vasya', 'username' => 'vasya'],
			'date' => 0,
			'text' => 'hi',
		]]));
		self::assertTrue($this->app->access->hasSuRight($vasya->id));
	}

	public function testItWorksInGroups(): void {
		$vasya = $this->player('vasya');
		self::assertStringContainsString('Дала право суперадмина', $this->sayInGroup($this->owner, '/su grant @vasya'));
		self::assertStringContainsString('Режим суперадмина: выключен.', $this->sayInGroup($vasya, '/su'));
		self::assertStringContainsString('Включила режим суперадмина', $this->sayInGroup($vasya, '/su on'));
		self::assertTrue($this->app->access->isSuper($vasya->id));
		self::assertStringContainsString('Режим суперадмина: включён.', $this->sayInGroup($this->owner, '/su'));
		self::assertStringContainsString('Выключила режим суперадмина', $this->sayInGroup($vasya, '/su@' . self::BOT_USERNAME . ' off'));
	}

	// ---- a player without the right

	public function testWithoutTheRightSuIsAnUnknownCommand(): void {
		$vasya = $this->player('vasya');
		$petya = $this->player('petya');
		$unknown = $this->say($vasya, '/nosuchthing');
		self::assertSame(self::UNKNOWN, $unknown);

		foreach (['/su', '/su on', '/su off', '/su list', '/su grant @petya', '/su revoke @petya', '/SU on'] as $command) {
			self::assertSame($unknown, $this->say($vasya, $command), $command);
		}
		self::assertFalse($this->app->access->hasSuRight($petya->id));
		self::assertFalse($this->app->access->isSuper($vasya->id));
		self::assertSame([], $this->suLines());
	}

	public function testWithoutTheRightSuIsSilentInGroupsLikeAnyUnknownCommand(): void {
		$vasya = $this->player('vasya');
		$this->sayInGroup($vasya, '/nosuchthing');
		$unknown_requests = count($this->http->sent('sendMessage'));
		$this->sayInGroup($vasya, '/su on');
		self::assertSame($unknown_requests, count($this->http->sent('sendMessage')));
		self::assertFalse($this->app->access->hasSuRight($vasya->id));
	}

	public function testChatAdminAndRegularPlayerNeverSeeSuInHelpOrMenu(): void {
		$vasya = $this->player('vasya');
		foreach ([$vasya, $this->admin] as $who) {
			self::assertStringNotContainsString('/su', $this->say($who, '/help'));
		}
		self::assertSame([], $this->http->sent('setMyCommands'));
	}

	public function testRevokedPlayerGoesBackToUnknown(): void {
		$vasya = $this->superadmin('vasya');
		self::assertStringContainsString('Режим суперадмина', $this->say($vasya, '/su'));
		$this->say($this->owner, '/su revoke @vasya');
		self::assertSame(self::UNKNOWN, $this->say($vasya, '/su'));
		self::assertStringNotContainsString('/su', $this->say($vasya, '/help'));
	}

	// ---- help and menu

	public function testHelpShowsSuOnlyToThoseWithTheRight(): void {
		$vasya = $this->superadmin('vasya', false);
		$help = $this->say($vasya, '/help');
		self::assertStringContainsString('/su — режим суперадмина', $help);
		self::assertStringNotContainsString('/su grant', $help, 'mode off: no grant hint');
		self::assertStringNotContainsString('Только для главного админа', $help);

		$this->say($vasya, '/su on');
		self::assertStringContainsString('/su grant @ник', $this->say($vasya, '/help'));

		$owner_help = $this->say($this->owner, '/help');
		self::assertStringContainsString('/su — режим суперадмина', $owner_help);
		self::assertStringContainsString('/su grant @ник', $owner_help);

		$this->say($this->owner, '/su off');
		$owner_off = $this->say($this->owner, '/help');
		self::assertStringContainsString('/su — режим суперадмина', $owner_off, 'the owner can always find /su');
		self::assertStringContainsString('/su grant @ник', $owner_off, 'and can always grant');
		self::assertStringNotContainsString('Только для главного админа', $owner_off);
	}

	public function testMenuFollowsGrantRevokeAndMode(): void {
		$vasya = $this->player('vasya');
		$this->http->reset();
		$this->say($this->owner, '/su grant @vasya');
		$menus = $this->menusFor($vasya);
		self::assertCount(1, $menus);
		$names = self::commandNames($menus[0]);
		self::assertContains('su', $names);
		self::assertNotContains('addplayer', $names, 'mode is off');
		self::assertSame(['type' => 'chat', 'chat_id' => $vasya->tg_user_id], $menus[0]['scope']);

		$this->http->reset();
		$this->say($vasya, '/su on');
		$names = self::commandNames($this->menusFor($vasya)[0]);
		self::assertContains('su', $names);
		self::assertContains('addplayer', $names);
		self::assertContains('players_import', $names);
		self::assertSame('help_level', end($names));

		$this->http->reset();
		$this->say($this->owner, '/su revoke @vasya');
		self::assertCount(1, $this->menusFor($vasya, 'deleteMyCommands'), 'back to the common private menu');
		self::assertSame([], $this->menusFor($vasya));
	}

	public function testOwnerMenuLosesOwnerCommandsWithTheModeOffButKeepsSu(): void {
		$this->http->reset();
		$this->say($this->owner, '/su off');
		$names = self::commandNames($this->menusFor($this->owner)[0]);
		self::assertContains('su', $names);
		self::assertNotContains('addplayer', $names);
	}

	public function testAMenuTelegramRefusesDoesNotBreakTheCommand(): void {
		$vasya = $this->player('vasya');
		$this->http->onTelegram('setMyCommands', static fn (): array => ['ok' => false, 'description' => 'chat not found', 'error_code' => 400]);
		self::assertStringContainsString('Дала право суперадмина', $this->say($this->owner, '/su grant @vasya'));
		self::assertTrue($this->app->access->hasSuRight($vasya->id));
	}

	// ---- the log

	public function testOnlySuOperationsAreLogged(): void {
		$vasya = $this->player('vasya');
		$this->say($this->owner, '/su');
		$this->say($this->owner, '/su list');
		$this->say($vasya, '/su on');
		self::assertSame([], $this->suLines(), 'status, list and refused calls are not operations');

		$this->say($this->owner, '/su grant @vasya');
		$this->say($vasya, '/su on');
		$this->say($vasya, '/su off');
		$this->say($this->owner, '/su revoke @vasya');
		$this->say($this->owner, '/su off');

		$lines = $this->suLines();
		self::assertCount(5, $lines);
		self::assertMatchesRegularExpression('/^\[\d{4}-\d\d-\d\d \d\d:\d\d:\d\d MSK\] grant by @andrey \(id \d+, tg 1000\) target @vasya \(id \d+, tg \d+\)$/', $lines[0]);
		self::assertMatchesRegularExpression('/\] on by @vasya \(id \d+, tg \d+\)$/', $lines[1]);
		self::assertMatchesRegularExpression('/\] off by @vasya \(id \d+, tg \d+\)$/', $lines[2]);
		self::assertMatchesRegularExpression('/\] revoke by @andrey .* target @vasya /', $lines[3]);
		self::assertMatchesRegularExpression('/\] off by @andrey \(id \d+, tg 1000\)$/', $lines[4]);
		self::assertSame(['su.log'], array_map('basename', glob($this->log_dir . '/*') ?: []), 'nothing else is written');
	}

	// ---- the player card

	public function testCardCheckboxFollowsTheGrantRule(): void {
		$vasya = $this->player('vasya');
		$boot = $this->app->player_form->bootstrap($this->cardInit($this->owner, $vasya), '');
		self::assertTrue($boot['can_manage_su']);
		self::assertFalse($boot['player']['can_su']);
		self::assertFalse($boot['player']['is_owner']);

		$boss = $this->superadmin('boss');
		self::assertTrue($this->app->player_form->bootstrap($this->cardInit($boss, $vasya), '')['can_manage_su']);

		$own = $this->app->player_form->bootstrap($this->cardInit($this->owner, $this->owner), '');
		self::assertTrue($own['player']['can_su'] || $own['player']['is_owner']);
		self::assertTrue($own['player']['is_owner']);
	}

	public function testCardCheckboxGrantsAndRevokesLikeTheCommandAndLogs(): void {
		$vasya = $this->player('vasya');
		$init = $this->cardInit($this->owner, $vasya);

		$this->http->reset();
		$this->app->player_form->save($init, '', ['can_su' => true]);
		self::assertTrue($this->app->access->hasSuRight($vasya->id));
		self::assertCount(1, $this->menusFor($vasya), 'the menu is refreshed');
		self::assertMatchesRegularExpression('/grant by @andrey .* target @vasya /', $this->suLog());

		$this->app->players->setSuMode($vasya->id, true);
		$this->app->player_form->save($init, '', ['can_su' => false]);
		$row = $this->fresh($vasya);
		self::assertFalse($row->can_su);
		self::assertFalse($row->su_mode);
		self::assertCount(2, $this->suLines());

		$this->app->player_form->save($init, '', ['can_su' => false]);
		self::assertCount(2, $this->suLines(), 'unchanged: nothing is logged');
	}

	public function testCardCheckboxRefusesTheOwnerRightAndPlayersWithoutTelegram(): void {
		$ghost = $this->insertPlaceholder('ghost');
		try {
			$this->app->player_form->save($this->cardInit($this->owner, $ghost), '', ['can_su' => true]);
			self::fail('no Telegram: no right');
		} catch (\P1Bot\Domain\Exception\ValidationError $e) {
			self::assertStringContainsString('нет Telegram', $e->getMessage());
		}
		self::assertFalse($this->fresh($ghost)->can_su);

		$this->expectException(PermissionDenied::class);
		$this->app->player_form->save($this->cardInit($this->owner, $this->owner), '', ['can_su' => false]);
	}

	public function testCardNeedsTheModeEvenForTheOwner(): void {
		$vasya = $this->player('vasya');
		$this->say($this->owner, '/su off');
		$this->expectException(WebAppFormError::class);
		$this->app->player_form->bootstrap($this->cardInit($this->owner, $vasya), '');
	}

	public function testCardStaysClosedForARegularPlayerAndAChatAdmin(): void {
		$vasya = $this->player('vasya');
		foreach ([$vasya, $this->admin] as $who) {
			try {
				$this->app->player_form->save($this->cardInit($who, $vasya), '', ['can_su' => true]);
				self::fail('forbidden');
			} catch (WebAppFormError $e) {
				self::assertSame(WebAppFormError::FORBIDDEN, $e->reason);
			}
		}
		self::assertFalse($this->fresh($vasya)->can_su);
	}

	// ---- storage

	public function testTheRightIsBoundToTheTelegramAccount(): void {
		$vasya = $this->superadmin('vasya');
		$this->app->players->unbindTelegram($vasya->id);
		$row = $this->fresh($vasya);
		self::assertFalse($row->can_su);
		self::assertNull($row->su_mode);

		$petya = $this->superadmin('petya');
		$this->app->players->softDelete($petya->id, $this->clock->now());
		self::assertFalse($this->app->access->hasSuRight($petya->id));
		self::assertFalse($this->app->access->isSuper($petya->id));
	}

	public function testNewPlayersHaveNoRightAndNoStoredMode(): void {
		$vasya = $this->player('vasya');
		self::assertFalse($vasya->can_su);
		self::assertNull($vasya->su_mode);
		self::assertFalse($this->app->access->hasSuRight($vasya->id));
		self::assertSame([], $this->app->players->listWithSuRight());
	}

	public function testListWithSuRightIsInIdOrderWithoutDeleted(): void {
		$a = $this->superadmin('aaa');
		$b = $this->superadmin('bbb');
		$gone = $this->superadmin('ccc');
		$this->app->players->softDelete($gone->id, $this->clock->now());
		self::assertSame([$a->id, $b->id], array_map(static fn (Player $p): int => $p->id, $this->app->players->listWithSuRight()));
	}

	public function testWithoutALogsPathNothingIsWrittenAndNothingFails(): void {
		$this->log_dir = '';
		$this->setUp();
		$this->player('vasya');
		self::assertStringContainsString('Дала право суперадмина', $this->say($this->owner, '/su grant @vasya'));
	}
}
