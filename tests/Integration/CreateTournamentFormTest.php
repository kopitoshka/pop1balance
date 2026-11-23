<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\App\CreateAccess;
use P1Bot\App\CreateTournamentForm;
use P1Bot\App\WebAppFormError;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Support\DateFormat;
use P1Bot\Telegram\WebAppInitData;
use P1Bot\Tests\Support\IntegrationTestCase;

/**
	/create_tournament, «➕ Создать турнир» on the lists and the Mini App form API.
*/
class CreateTournamentFormTest extends IntegrationTestCase {

	protected function config(): array {
		$c = parent::config();
		$c['telegram']['webhook_url'] = 'https://pop1.example.com/webhook.php';
		return $c;
	}

	/**
		@return list<array<string, mixed>>
	*/
	protected function buttonsOf(array $message): array {
		$out = [];
		foreach ($message['reply_markup']['inline_keyboard'] ?? [] as $row) {
			foreach ($row as $btn) {
				$out[] = $btn;
			}
		}
		return $out;
	}

	/**
		@return array<string, mixed>|null
	*/
	protected function findButton(string $text): ?array {
		$found = null;
		foreach ($this->http->sent('sendMessage') as $message) {
			foreach ($this->buttonsOf($message) as $btn) {
				if (($btn['text'] ?? '') === $text) {
					$found = $btn;
				}
			}
		}
		return $found;
	}

	protected function tokenOf(array $btn): string {
		$url = (string) ($btn['web_app']['url'] ?? '');
		self::assertStringStartsWith('https://pop1.example.com/webapp/create.html?ctx=', $url);
		parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
		return (string) $q['ctx'];
	}

	/**
		@param array<string, mixed> $override
		@return array<string, mixed>
	*/
	protected function form(array $override = []): array {
		return $override + [
			'title' => 'Кубок выходного дня',
			'date' => '2026-11-23',
			'time' => '20:00',
			'team_size' => 3,
			'level_mode' => 'players',
			'levels' => [3, 3, 5],
			'map' => 'Classic',
			'description' => '',
			'max_players' => 24,
		];
	}

	protected function formError(Player $who, array $form): WebAppFormError {
		$this->app->chats->ensure((int) $who->tg_user_id, $who->display_name);
		$token = (string) $this->app->create_form->issueToken($who);
		try {
			$this->app->create_form->submit($this->initDataFor($who), $token, $form);
		} catch (WebAppFormError $e) {
			return $e;
		}
		self::fail('Expected a form error');
	}

	public function testCommandInPrivateOffersTheForm(): void {
		$this->dispatch($this->privateMessage($this->owner, '/create_tournament'));
		$sent = $this->http->sent('sendMessage');
		$last = end($sent);
		self::assertSame(CreateTournamentForm::OFFER_TEXT, $last['text']);
		$buttons = $this->buttonsOf($last);
		self::assertSame('📝 Создать турнир', $buttons[0]['text']);
		$this->tokenOf($buttons[0]);
		self::assertSame('✖️ Отмена', $buttons[1]['text']);
		self::assertSame('tcreatex:0', $buttons[1]['callback_data']);
		self::assertNull($this->app->tournament_repo->find(1), 'nothing is created by the command');
	}

	public function testCancelButtonDismissesTheOffer(): void {
		$this->dispatch($this->privateMessage($this->owner, '/create_tournament'));
		$this->http->reset();
		$this->dispatch($this->press($this->owner, 'tcreatex:0', 1000));
		$edit = $this->http->sent('editMessageText')[0];
		self::assertSame(1000, $edit['message_id']);
		self::assertStringContainsString('не создаём', $edit['text']);
		self::assertSame('Отменила', $this->http->sent('answerCallbackQuery')[0]['text']);
	}

	public function testCommandInGroupLeadsToPrivate(): void {
		$this->dispatch($this->groupMessage($this->owner, '/create_tournament'));
		$sent = $this->http->sent('sendMessage');
		$last = end($sent);
		self::assertSame(self::CHAT_ID, $last['chat_id']);
		self::assertSame(CreateTournamentForm::GROUP_TEXT, $last['text']);
		$buttons = $this->buttonsOf($last);
		self::assertCount(1, $buttons);
		self::assertSame('✉️ Продолжить в личке', $buttons[0]['text']);
		self::assertSame('https://t.me/pop1_bot?start=create', $buttons[0]['url']);
		self::assertArrayNotHasKey('web_app', $buttons[0], 'web_app buttons do not work in groups');

		// The deep link opens the same offer in private.
		$this->http->reset();
		$this->dispatch($this->privateMessage($this->owner, '/start create'));
		self::assertSame(CreateTournamentForm::OFFER_TEXT, $this->http->lastText());
		self::assertNotNull($this->findButton('📝 Создать турнир'));
	}

	public function testCommandRefusedWithoutCreateAccess(): void {
		$vasya = $this->player('vasya');
		$this->withoutSharedOwnerGroup($vasya);
		$this->dispatch($this->privateMessage($vasya, '/create_tournament'));
		self::assertSame(CreateAccess::REFUSAL, html_entity_decode($this->http->lastText()));
		self::assertNull($this->findButton('📝 Создать турнир'));

		$this->http->reset();
		$this->dispatch($this->groupMessage($vasya, '/create_tournament'));
		self::assertSame(CreateAccess::REFUSAL, html_entity_decode($this->http->lastText()));
		self::assertNull($this->findButton('✉️ Продолжить в личке'));

		$this->http->reset();
		$this->dispatch($this->privateMessage($vasya, '/start create'));
		self::assertSame(CreateAccess::REFUSAL, html_entity_decode($this->http->lastText()));
	}

	public function testFormSubmitRefusedWithoutCreateAccess(): void {
		$vasya = $this->player('vasya');
		$this->everyoneIsGroupMember();
		$token = (string) $this->app->create_form->issueToken($vasya);
		$this->withoutSharedOwnerGroup($vasya);
		try {
			$this->app->create_form->bootstrap($this->initDataFor($vasya), $token);
			self::fail('bootstrap should refuse');
		} catch (WebAppFormError $e) {
			self::assertSame(WebAppFormError::FORBIDDEN, $e->reason);
			self::assertSame(CreateAccess::REFUSAL, $e->getMessage());
		}
		$this->app->chats->ensure($this->tgIdOf($vasya), 'Vasya');
		try {
			$this->app->create_form->submit($this->initDataFor($vasya), $token, $this->form());
			self::fail('submit should refuse');
		} catch (WebAppFormError $e) {
			self::assertSame(WebAppFormError::FORBIDDEN, $e->reason);
		}
		self::assertNull($this->app->tournament_repo->find(1));
	}

	public function testWithoutMiniAppUrlTheCommandSuggestsText(): void {
		$app = new \P1Bot\Bootstrap($this->db, $this->clock, parent::config(), $this->http);
		self::assertFalse($app->create_form->isAvailable());
		$app->router->handle(\P1Bot\Telegram\Update::fromArray($this->privateMessage($this->owner, '/create_tournament')));
		self::assertStringContainsString('Форма создания сейчас недоступна', $this->http->lastText());
	}

	public function testCommandCancelsPendingTextInput(): void {
		$cap = $this->player('capx');
		$pending = $this->pendingFor($cap);
		$this->dispatch($this->privateMessage($cap, '/create_tournament'));
		self::assertNull($pending->peekLatest($cap->id, $this->tgIdOf($cap), $this->clock->now()));
	}

	public function testListButtonOnlyForThoseWhoMayCreate(): void {
		// Empty list, owner, private: /tournaments and the «турниры» keyword carry the button.
		foreach (['/tournaments', 'турниры'] as $text) {
			$this->http->reset();
			$this->dispatch($this->privateMessage($this->owner, $text));
			$btn = $this->findButton('➕ Создать турнир');
			self::assertNotNull($btn, $text);
			$this->tokenOf($btn);
			self::assertStringContainsString('Сейчас активных турниров нет', implode("\n", array_column($this->http->sent('sendMessage'), 'text')), $text);
		}

		// One published tournament: the private list is the full card, without the create button.
		$this->openTournament(3);
		$this->http->reset();
		$this->dispatch($this->privateMessage($this->owner, '/tournaments'));
		self::assertNull($this->findButton('➕ Создать турнир'));
		foreach ($this->http->sent('sendMessage') as $message) {
			foreach ($this->buttonsOf($message) as $btn) {
				self::assertStringStartsNotWith('popen:', (string) ($btn['callback_data'] ?? ''));
			}
		}

		// With a draft as well, the organizer sees the compact list; the button comes after the «open» buttons.
		self::assertNull($this->newDraft($this->owner, '24.11 21:00 | Второй | 2 | 3,3'));
		$this->http->reset();
		$this->dispatch($this->privateMessage($this->owner, '/tournaments'));
		$sent = $this->http->sent('sendMessage');
		$rows = end($sent)['reply_markup']['inline_keyboard'];
		self::assertSame('popen:1', $rows[0][0]['callback_data']);
		self::assertSame('popen:2', $rows[1][0]['callback_data']);
		self::assertSame('➕ Создать турнир', end($rows)[0]['text']);

		// Group list (drafts hidden, one active): the card plus a deep link into the private chat, never a web_app button.
		$this->http->reset();
		$this->dispatch($this->groupMessage($this->owner, '/tournaments'));
		$btn = $this->findButton('➕ Создать турнир');
		self::assertSame('https://t.me/pop1_bot?start=create', $btn['url'] ?? null);

		// A player who may not create sees no button anywhere.
		$vasya = $this->player('vasya');
		$this->withoutSharedOwnerGroup($vasya);
		foreach ([$this->privateMessage($vasya, '/tournaments'), $this->privateMessage($vasya, 'турниры'), $this->groupMessage($vasya, '/tournaments')] as $raw) {
			$this->http->reset();
			$this->dispatch($raw);
			self::assertNull($this->findButton('➕ Создать турнир'));
		}
	}

	public function testBootstrapGivesLimitsAndLevelNames(): void {
		$token = (string) $this->app->create_form->issueToken($this->owner);
		$boot = $this->app->create_form->bootstrap($this->initDataFor($this->owner), $token);
		self::assertSame(['min' => 1, 'max' => 12, 'default' => 3], $boot['team_size']);
		self::assertSame(['default' => 24, 'max' => 1000], $boot['max_players']);
		self::assertSame(20, $boot['registration_close_minutes']);
		self::assertSame(3, $boot['default_level']);
		self::assertSame(['level' => 3, 'name' => 'БТР'], $boot['levels'][2]);
		self::assertCount(6, $boot['levels']);
		self::assertSame('2026-11-09 15:00:00', $boot['now_msk'], 'Moscow time, UTC+3');
		self::assertSame('2026-11-10', $boot['default_date']);
		self::assertSame('20:00', $boot['default_time']);
		self::assertSame('', $boot['default_title']);
		self::assertNull($boot['default_map']);
		self::assertSame('', $boot['default_description']);
		self::assertSame(['Classic', 'Evolving', 'Phoenix', 'custom'], $boot['maps']);
		self::assertSame('Выбери карту', $boot['map_placeholder']);
		self::assertSame(500, $boot['description_max']);
	}

	public function testBootstrapPrefillsTheOrganizersLastTitle(): void {
		self::assertNull($this->submitForm($this->owner, $this->form([
			'title' => 'Феникс четверговый',
			'map' => 'Phoenix',
			'description' => 'Играем до трёх',
			'time' => '21:15',
			'team_size' => 2,
			'level_mode' => 'sum',
			'points' => 8,
			'max_players' => 16,
		])));
		self::assertNull($this->submitForm($this->owner, $this->form([
			'title' => 'Кубок пятницы',
			'date' => '2026-11-24',
			'map' => 'Evolving',
			'description' => 'Микс <вечер>',
			'time' => '19:30',
			'team_size' => 4,
			'level_mode' => 'players',
			'levels' => [2, 3, 4, 5],
			'max_players' => 32,
		])));
		// A picture on the last tournament must not be offered as a default.
		$this->app->tournament_repo->update(2, ['image_path' => str_repeat('a', 32) . '.jpg']);
		$token = (string) $this->app->create_form->issueToken($this->owner);
		$boot = $this->app->create_form->bootstrap($this->initDataFor($this->owner), $token);
		self::assertSame('Кубок пятницы', $boot['default_title']);
		self::assertSame('Evolving', $boot['default_map']);
		self::assertSame('Микс <вечер>', $boot['default_description']);
		self::assertSame('19:30', $boot['default_time']);
		self::assertSame('2026-11-10', $boot['default_date'], 'the date of the last tournament is not reused');
		self::assertSame(4, $boot['team_size']['default']);
		self::assertSame(32, $boot['max_players']['default']);
		self::assertSame('players', $boot['default_level_mode']);
		self::assertSame([2, 3, 4, 5], $boot['default_levels']);
		self::assertSame($this->app->tournament_repo->find(2)->max_points, $boot['default_points']);
		self::assertArrayNotHasKey('default_image', $boot);
		self::assertArrayNotHasKey('default_image_url', $boot);

		$admin = $this->admin;
		$token = (string) $this->app->create_form->issueToken($admin);
		$boot = $this->app->create_form->bootstrap($this->initDataFor($admin), $token);
		self::assertSame('', $boot['default_title'], 'another organizer does not inherit the title');
		self::assertNull($boot['default_map']);
		self::assertSame(3, $boot['team_size']['default']);
		self::assertSame('20:00', $boot['default_time']);
	}

	public function testSubmitPerPlayerCreatesDraftAndSendsCard(): void {
		self::assertNull($this->submitForm($this->owner, $this->form(['max_players' => 30])));
		$t = $this->app->tournament_repo->find(1);
		self::assertSame('Кубок выходного дня', $t->title);
		self::assertSame('2026-11-23 17:00:00', DateFormat::toDb($t->starts_at), '20:00 МСК = 17:00 UTC');
		self::assertSame(3, $t->team_size);
		self::assertSame('3,3,5', $t->reference_team);
		self::assertSame(11, $t->max_points);
		self::assertSame(30, $t->max_players);
		self::assertSame($this->owner->id, $t->organizer_id);
		self::assertSame(TournamentStatus::DRAFT, $t->status, 'the form never publishes');
		self::assertSame(self::CHAT_ID, $t->chat_id, 'origin chosen like the old /new');

		$sent = $this->http->sent('sendMessage');
		self::assertCount(1, $sent);
		$card = $sent[0];
		self::assertSame($this->tgIdOf($this->owner), $card['chat_id']);
		self::assertStringContainsString('Турнир №1 (черновик)', $card['text']);
		self::assertStringContainsString('🗺 Карта: Classic', $card['text']);
		self::assertStringContainsString('⚖️ Макс. уровень: 11', $card['text']);
		self::assertStringContainsString('По игрокам: БТР, БТР, Танк', $card['text']);
		self::assertStringNotContainsStringIgnoringCase('эталон', $card['text']);
		self::assertStringEndsWith('Публикуем или что-то поправить?', $card['text']);
		self::assertSame('pub:1', $card['reply_markup']['inline_keyboard'][0][0]['callback_data']);
		$fix = $card['reply_markup']['inline_keyboard'][0][1];
		self::assertStringContainsString('Поправить', $fix['text']);
		self::assertArrayHasKey('web_app', $fix);
		self::assertSame([], $this->http->sent('editMessageText'), 'nothing published');
		self::assertSame(1000, $t->card_msg_id);
	}

	public function testSubmitReturnsCloseAndKeepsTheTokenUsable(): void {
		$this->app->chats->ensure($this->tgIdOf($this->owner), 'Андрей');
		$token = (string) $this->app->create_form->issueToken($this->owner);
		$result = $this->app->create_form->submit($this->initDataFor($this->owner), $token, $this->form());
		self::assertTrue($result['ok']);
		self::assertTrue($result['close']);
		self::assertSame(1, $result['tournament_id']);
	}

	public function testSubmitSumMode(): void {
		self::assertNull($this->submitForm($this->owner, $this->form([
			'level_mode' => 'sum',
			'points' => 10,
			'levels' => null,
		])));
		$t = $this->app->tournament_repo->find(1);
		self::assertNull($t->reference_team);
		self::assertSame(10, $t->max_points);
		self::assertStringContainsString('Макс. уровень: 10', $this->http->lastText());
	}

	public function testMoscowTimeWhateverTheServerZone(): void {
		$zone = date_default_timezone_get();
		date_default_timezone_set('America/Los_Angeles');
		try {
			self::assertNull($this->submitForm($this->owner, $this->form(['date' => '2026-12-31', 'time' => '23:30'])));
		} finally {
			date_default_timezone_set($zone);
		}
		self::assertSame('2026-12-31 20:30:00', DateFormat::toDb($this->app->tournament_repo->find(1)->starts_at));

		// 14:59 МСК today is already past (now is 15:00 МСК), 15:01 is fine.
		$e = $this->formError($this->owner, $this->form(['date' => '2026-11-09', 'time' => '14:59']));
		self::assertSame('start', $e->field);
		self::assertStringContainsString('уже прошло', $e->getMessage());
		self::assertNull($this->submitForm($this->owner, $this->form(['date' => '2026-11-09', 'time' => '15:01'])));
	}

	public function testInvalidInputsReportTheField(): void {
		$cases = [
			'title' => [['title' => '   '], 'Введи название турнира'],
			'title too long' => [['title' => str_repeat('я', 256)], 'не длиннее 255'],
			'no date' => [['date' => ''], 'Выбери дату'],
			'bad date' => [['date' => '2026-02-30'], 'Выбери дату'],
			'bad time' => [['time' => '24:00'], 'Выбери время'],
			'team size 0' => [['team_size' => 0], 'от 1 до 12'],
			'team size 13' => [['team_size' => 13], 'от 1 до 12'],
			'team size text' => [['team_size' => 'три'], 'от 1 до 12'],
			'levels count' => [['levels' => [3, 3]], 'Укажи уровень каждого игрока: 3 игрока'],
			'level 7' => [['levels' => [3, 3, 7]], 'от 1 до 6'],
			'no mode' => [['level_mode' => ''], 'по игрокам или суммой'],
			'sum too big' => [['level_mode' => 'sum', 'points' => 28], 'от 3 до 27'],
			'sum too small' => [['level_mode' => 'sum', 'points' => 2], 'от 3 до 27'],
			'limit below team' => [['max_players' => 2], 'от 3 до 1000'],
			'limit too big' => [['max_players' => 1001], 'от 3 до 1000'],
			'no map' => [['map' => ''], 'Выбери карту'],
			'bad map' => [['map' => 'Dust'], 'Classic, Evolving, Phoenix или custom'],
			'description too long' => [['description' => str_repeat('я', 501)], 'не длиннее 500'],
		];
		$fields = [
			'title' => 'title', 'title too long' => 'title', 'no date' => 'start', 'bad date' => 'start',
			'bad time' => 'start', 'team size 0' => 'team_size', 'team size 13' => 'team_size',
			'team size text' => 'team_size', 'levels count' => 'level', 'level 7' => 'level', 'no mode' => 'level',
			'sum too big' => 'level', 'sum too small' => 'level', 'limit below team' => 'max_players',
			'limit too big' => 'max_players', 'no map' => 'map', 'bad map' => 'map',
			'description too long' => 'description',
		];
		foreach ($cases as $name => [$override, $needle]) {
			$e = $this->formError($this->owner, $this->form($override));
			self::assertSame($fields[$name], $e->field, $name);
			self::assertSame(WebAppFormError::INVALID, $e->reason, $name);
			self::assertStringContainsString($needle, $e->getMessage(), $name);
		}

		// Several errors at once come back together.
		$e = $this->formError($this->owner, $this->form(['title' => '', 'max_players' => 0]));
		self::assertSame(['title', 'max_players'], array_keys($e->fields));
		self::assertSame('title', $e->toArray()['field']);
		self::assertNull($this->app->tournament_repo->find(1));
		self::assertSame([], $this->http->sent('sendMessage'));
	}

	public function testTeamSizeOne(): void {
		self::assertNull($this->submitForm($this->owner, $this->form([
			'team_size' => 1,
			'levels' => [5],
			'max_players' => 1,
		])));
		$t = $this->app->tournament_repo->find(1);
		self::assertSame(1, $t->team_size);
		self::assertSame(5, $t->max_points);
		self::assertSame(1, $t->max_players);
		self::assertSame('Макс. уровень: 5', $t->levelLabel());

		self::assertNull($this->submitForm($this->owner, $this->form([
			'title' => 'Соло суммой',
			'team_size' => 1,
			'level_mode' => 'sum',
			'points' => 6,
		])));
		self::assertSame(6, $this->app->tournament_repo->find(2)->max_points);
		$e = $this->formError($this->owner, $this->form(['team_size' => 1, 'level_mode' => 'sum', 'points' => 7]));
		self::assertStringContainsString('от 1 до 6', $e->getMessage());

		// A solo team is just its captain: full, registered, nobody to add.
		$this->app->tournaments->openRegistration($this->owner->id, 1);
		$cap = $this->player('solo_cap');
		$entry = $this->app->teams->createTeam(1, $cap->id, 'Одиночка', []);
		self::assertTrue($this->app->teams->get($entry->team->id)->team->isRegistered());
		try {
			$this->app->teams->addPlayer($cap->id, $entry->team->id, $this->player('extra')->id);
			self::fail('no room in a team of one');
		} catch (\P1Bot\Domain\Exception\ValidationError $e) {
			self::assertStringContainsString('мест нет', $e->getMessage());
		}
		$lineup = $this->app->announcer->lineupText($this->app->tournaments->get(1));
		self::assertStringContainsString('Одиночка', $lineup);
	}

	public function testTeamSizeTwelve(): void {
		self::assertNull($this->submitForm($this->owner, $this->form([
			'team_size' => 12,
			'levels' => array_fill(0, 12, 3),
			'max_players' => 24,
		])));
		$t = $this->app->tournament_repo->find(1);
		self::assertSame(12, $t->team_size);
		self::assertSame(36, $t->max_points);
		self::assertSame('Макс. уровень: 36', $t->levelLabel());

		self::assertNull($this->submitForm($this->owner, $this->form([
			'title' => 'Дюжина суммой',
			'team_size' => 12,
			'level_mode' => 'sum',
			'points' => 72,
			'max_players' => 1000,
		])));
		self::assertSame(72, $this->app->tournament_repo->find(2)->max_points);
		$e = $this->formError($this->owner, $this->form(['team_size' => 12, 'level_mode' => 'sum', 'points' => 271]));
		self::assertStringContainsString('от 12 до 270', $e->getMessage());
		$e = $this->formError($this->owner, $this->form(['team_size' => 12, 'levels' => [3, 3, 3], 'max_players' => 11]));
		self::assertSame(['level', 'max_players'], array_keys($e->fields));
	}

	public function testCreateButtonTokenStaysReusableAndDedupsAnOpening(): void {
		$this->app->chats->ensure((int) $this->owner->tg_user_id, $this->owner->display_name);
		$token = (string) $this->app->create_form->issueToken($this->owner);
		$first = $this->app->create_form->submit($this->initDataFor($this->owner), $token, $this->form([
			'opening_id' => 'create_open_1',
		]));
		self::assertSame(1, $first['tournament_id']);
		$again = $this->app->create_form->submit($this->initDataFor($this->owner), $token, $this->form([
			'title' => 'Другой',
			'opening_id' => 'create_open_1',
		]));
		self::assertSame(1, $again['tournament_id']);
		self::assertSame('Кубок выходного дня', $this->app->tournament_repo->find(1)->title);
		self::assertSame(1, count($this->app->tournament_repo->listByStatuses([TournamentStatus::DRAFT])));

		$second = $this->app->create_form->submit($this->initDataFor($this->owner), $token, $this->form([
			'title' => 'Ещё кубок',
			'opening_id' => 'create_open_2',
		]));
		self::assertSame(2, $second['tournament_id']);
		self::assertSame(2, count($this->app->tournament_repo->listByStatuses([TournamentStatus::DRAFT])));
	}

	public function testExpiredOrForeignFormIsRejectedClearly(): void {

		$token = (string) $this->app->create_form->issueToken($this->owner);
		$this->clock->advance('+' . (CreateTournamentForm::TTL_SECONDS + 60) . ' seconds');
		try {
			$this->app->create_form->submit($this->initDataFor($this->owner), $token, $this->form(['date' => '2026-11-30']));
			self::fail('expired');
		} catch (WebAppFormError $e) {
			self::assertSame(WebAppFormError::EXPIRED, $e->reason);
			self::assertStringContainsString('Форма устарела', $e->getMessage());
		}

		// initData older than an hour (the form was left open).
		$token = (string) $this->app->create_form->issueToken($this->owner);
		$old_init = $this->initDataFor($this->owner);
		$this->clock->advance('+2 hours');
		try {
			$this->app->create_form->bootstrap($old_init, $token);
			self::fail('stale initData');
		} catch (WebAppFormError $e) {
			self::assertSame(WebAppFormError::EXPIRED, $e->reason);
		}

		// Somebody else's initData with the owner's token.
		$token = (string) $this->app->create_form->issueToken($this->owner);
		try {
			$this->app->create_form->bootstrap($this->initDataFor($this->player('intruder')), $token);
			self::fail('foreign');
		} catch (WebAppFormError $e) {
			self::assertSame(WebAppFormError::FORBIDDEN, $e->reason);
		}

		// Bad signature.
		try {
			$this->app->create_form->bootstrap(WebAppInitData::sign(['auth_date' => '1', 'user' => '{"id":1}'], 'x:y'), $token);
			self::fail('bad signature');
		} catch (WebAppFormError $e) {
			self::assertSame(WebAppFormError::EXPIRED, $e->reason);
		}

		// A picker token is not a form token.
		$picker = $this->app->webapp_picker->levelButton($this->owner, $this->tgIdOf($this->owner), false);
		parse_str((string) parse_url((string) $picker['web_app']['url'], PHP_URL_QUERY), $q);
		try {
			$this->app->create_form->bootstrap($this->initDataFor($this->owner), (string) $q['ctx']);
			self::fail('picker token');
		} catch (WebAppFormError $e) {
			self::assertSame(WebAppFormError::EXPIRED, $e->reason);
		}
		self::assertNull($this->app->tournament_repo->find(1));
	}

	public function testMapAndDescriptionAreStoredEscapedOnCardAndAnnouncement(): void {
		self::assertNull($this->submitForm($this->owner, $this->form([
			'map' => 'custom',
			'description' => "Вечерний микс на <Фениксе>\nвторая строка",
		])));
		$t = $this->app->tournament_repo->find(1);
		self::assertSame('custom', $t->map);
		self::assertSame("Вечерний микс на <Фениксе>\nвторая строка", $t->description);
		$card = $this->http->sent('sendMessage')[0]['text'];
		self::assertStringContainsString('🗺 Карта: custom', $card);
		self::assertStringContainsString('Вечерний микс на &lt;Фениксе&gt;', $card);
		self::assertStringContainsString("вторая строка", $card);
		self::assertStringNotContainsString('<Фениксе>', $card);

		$this->dispatch($this->press($this->owner, 'pub:1', (int) $t->card_msg_id));
		$posted = array_values(array_filter(
			$this->http->sent('sendMessage'),
			static fn (array $m): bool => (int) $m['chat_id'] === self::CHAT_ID,
		));
		self::assertNotSame([], $posted);
		self::assertStringContainsString('🗺 Карта: custom', $posted[0]['text']);
		self::assertStringContainsString('Вечерний микс на &lt;Фениксе&gt;', $posted[0]['text']);

		$this->http->reset();
		$this->dispatch($this->privateMessage($this->owner, '/tournaments 1'));
		$messages = $this->http->sent('sendMessage');
		self::assertStringContainsString('🗺 Карта: custom', $messages[0]['text']);
		self::assertStringContainsString('Вечерний микс на &lt;Фениксе&gt;', $messages[0]['text']);
	}

	public function testEmptyDescriptionIsStoredAsNull(): void {
		self::assertNull($this->submitForm($this->owner, $this->form(['map' => 'Phoenix', 'description' => '   '])));
		$t = $this->app->tournament_repo->find(1);
		self::assertSame('Phoenix', $t->map);
		self::assertNull($t->description);
		self::assertStringContainsString('🗺 Карта: Phoenix', $this->http->sent('sendMessage')[0]['text']);
		self::assertStringNotContainsString('Описание', $this->http->sent('sendMessage')[0]['text']);
	}

	public function testSubmitClearsPendingTextInput(): void {
		$cap = $this->player('capy');
		$this->openTournament(3);
		$pending = $this->pendingFor($cap);
		$this->everyoneIsGroupMember();
		self::assertNull($this->submitForm($cap, $this->form()));
		self::assertNull($pending->peekLatest($cap->id, $this->tgIdOf($cap), $this->clock->now()));
		self::assertSame($cap->id, $this->app->tournament_repo->find(2)->organizer_id);
	}

	/**
		A pending player action (as a /level «Может, ты про…» pick leaves) in the private chat.
	*/
	protected function pendingFor(\P1Bot\Domain\Model\Player $who): \P1Bot\Repository\PendingActionRepository {
		$pending = new \P1Bot\Repository\PendingActionRepository($this->db);
		$pending->create(
			$who->id,
			$this->tgIdOf($who),
			['kind' => 'level_show'],
			$this->clock->now()->modify('+5 minutes'),
			$this->clock->now(),
		);
		self::assertNotNull($pending->peekLatest($who->id, $this->tgIdOf($who), $this->clock->now()));
		return $pending;
	}
}
