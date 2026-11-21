<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Domain\TournamentStatus;
use P1Bot\Tests\Support\IntegrationTestCase;
use P1Bot\View\Texts;

class AdminCommandsTest extends IntegrationTestCase {

	public function testDraftUsesDefaultScaleAndPlayerLimit(): void {
		$this->newDraft($this->owner, '23.11 20:00 | Пары | 2 | Танк, слабый плюс');
		$t = $this->app->tournament_repo->find(1);
		self::assertSame(7, $t->max_points);
		self::assertNotNull($t->balance_id, 'default scale');
		self::assertSame(24, $t->max_players);
	}

	public function testClearYesReplyToCardPublishes(): void {
		$this->newDraft($this->owner, '23.11 20:00 | Кубок | 3 | 3,3,4');
		$card_id = $this->app->tournament_repo->find(1)->card_msg_id;

		$this->dispatch($this->privateMessage($this->owner, 'Да, всё верно!', ['reply_to_message' => [
			'message_id' => $card_id,
			'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Bot'],
		]]));
		$t = $this->app->tournament_repo->find(1);
		self::assertSame(TournamentStatus::REGISTRATION, $t->status);
		self::assertNotNull($t->announce_msg_id);
		$edit = $this->http->sent('editMessageText')[0];
		self::assertSame($card_id, $edit['message_id']);
		self::assertStringContainsString('Опубликовано', $edit['text']);
		self::assertStringNotContainsString('Публикуем или', $edit['text']);
		self::assertStringContainsString('Опубликовала', $this->http->lastText());
	}

	public function testUnclearReplyToCardDoesNotPublish(): void {
		$this->newDraft($this->owner, '23.11 20:00 | Кубок | 3 | 3,3,4');
		$card_id = $this->app->tournament_repo->find(1)->card_msg_id;
		foreach (['да, но время 21:00', 'не, подожди', 'хм', 'вроде да?'] as $answer) {
			$this->dispatch($this->privateMessage($this->owner, $answer, ['reply_to_message' => [
				'message_id' => $card_id,
				'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Bot'],
			]]));
			self::assertSame(TournamentStatus::DRAFT, $this->app->tournament_repo->find(1)->status, $answer);
			self::assertStringContainsString('Не публикую', $this->http->lastText());
		}
	}

	public function testEditAndCancelCommandsAreGone(): void {
		$this->newDraft($this->owner, '23.11 20:00 | Кубок | 3 | 3,3,4');
		foreach (['/edit 1 лимит 30', '/edit 1 уровень 12', '/cancel 1', '/cancel'] as $command) {
			$this->dispatch($this->privateMessage($this->owner, $command));
			self::assertSame('Такой команды я не знаю 🤔 Подсказка: /help', $this->http->lastText(), $command);
		}
		$t = $this->app->tournament_repo->find(1);
		self::assertSame([TournamentStatus::DRAFT, 24, 10], [$t->status, $t->max_players, $t->max_points]);
		$this->confirmCard($this->owner);
		$this->dispatch($this->privateMessage($this->owner, '/cancel 1'));
		self::assertSame(TournamentStatus::REGISTRATION, $this->app->tournament_repo->find(1)->status);
		foreach ([Texts::help('pop1_bot', true, true), Texts::help('pop1_bot', false)] as $help) {
			self::assertStringNotContainsString('/edit', $help);
			self::assertStringNotContainsString('/cancel', $help);
		}
	}

	public function testEmptyListsPointToButtonsNotFreeText(): void {
		$this->dispatch($this->privateMessage($this->owner, '/tournaments'));
		self::assertStringContainsString('Сейчас активных турниров нет. Создать новый: /create_tournament.', $this->http->lastText());
		$this->dispatch($this->privateMessage($this->owner, '/team'));
		self::assertStringEndsWith('открой турнир (/tournaments) → «🛡 Собрать команду».', $this->http->lastText());
		foreach ([$this->http->lastText(), Texts::welcome('A'), Texts::welcomeTournament('A')] as $text) {
			self::assertStringNotContainsString('напиши', mb_strtolower($text));
		}
	}

	public function testNewCommandIsGone(): void {
		$this->dispatch($this->privateMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | 10 | 24'));
		self::assertStringContainsString('Такой команды я не знаю', $this->http->lastText());
		$this->dispatch($this->privateMessage($this->owner, '/new'));
		self::assertStringContainsString('Такой команды я не знаю', $this->http->lastText());
		$this->dispatch($this->groupMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | 10'));
		self::assertNull($this->app->tournament_repo->find(1));
		self::assertStringNotContainsString('Публикуем', implode("\n", array_column($this->http->sent('sendMessage'), 'text')));
	}

	public function testValidationErrorIsShownHumanly(): void {
		self::assertStringContainsString('от 1 до 12 игроков', (string) $this->newDraft($this->owner, '23.11 20:00 | Кубок | 13 | 3'));
		self::assertNull($this->app->tournament_repo->find(1));
	}

	public function testRegularPlayerCannotUseAdminCommands(): void {
		$vasya = $this->player('vasya');
		$this->withoutSharedOwnerGroup($vasya);
		$this->dispatch($this->privateMessage($vasya, '/create_tournament'));
		self::assertStringContainsString('организатор P1', $this->http->lastText());
		self::assertStringContainsString('организатор P1', (string) $this->newDraft($vasya, '23.11 20:00 | Кубок | 3 | 10'));
		self::assertNull($this->app->tournament_repo->find(1));
	}

	public function testTelegramChatAdminCanCreate(): void {
		$this->http->onTelegram('getChatAdministrators', function (): array {
			return ['ok' => true, 'result' => [['status' => 'administrator', 'user' => [
				'id' => $this->tgIdOf($this->admin),
				'is_bot' => false,
				'first_name' => 'Admin',
				'username' => 'admin2',
			]]]];
		});
		$this->newDraft($this->admin, '23.11 20:00 | Кубок | 2 | 3,3');
		self::assertSame($this->admin->id, $this->app->tournament_repo->find(1)->organizer_id);
	}

	public function testOpenPostsAnnouncementWithButtons(): void {
		$this->newDraft($this->owner, '23.11 20:00 | Кубок | 3 | 3,3,4');
		$this->http->reset();
		$this->dispatch($this->privateMessage($this->owner, '/open'));

		// /open on a draft does not publish: it shows the card and asks.
		$sent = $this->http->sent('sendMessage');
		self::assertCount(1, $sent);
		self::assertStringEndsWith('Публикуем или что-то поправить?', $sent[0]['text']);
		self::assertSame('pub:1', $sent[0]['reply_markup']['inline_keyboard'][0][0]['callback_data']);
		self::assertSame(TournamentStatus::DRAFT, $this->app->tournament_repo->find(1)->status);
		self::assertSame(1001, $this->app->tournament_repo->find(1)->card_msg_id);

		$this->http->reset();
		$this->confirmCard($this->owner);
		$sent = $this->http->sent('sendMessage');
		self::assertCount(2, $sent, 'announcement + short reply');
		$announcement = $sent[0];
		self::assertArrayNotHasKey('reply_parameters', $announcement);
		self::assertStringContainsString('<b>Кубок</b>', $announcement['text']);
		self::assertStringContainsString('🗓 23.11.2026 ⏰ 20:00 МСК', $announcement['text']);
		self::assertStringContainsString('средний (10 баллов на троих)', $announcement['text']);
		self::assertStringContainsString('Занято 0/24 · запас 0', $announcement['text']);
		self::assertSame(
			['text' => '💬 Записаться / вопросы в личке', 'url' => 'https://t.me/pop1_bot?start=t_1'],
			$announcement['reply_markup']['inline_keyboard'][0][0],
		);

		$t = $this->app->tournament_repo->find(1);
		self::assertSame(TournamentStatus::REGISTRATION, $t->status);
		self::assertSame(1002, $t->announce_msg_id, 'after the draft card and the /open card');
	}

	public function testOtherAdminCannotManageSomeoneElsesTournament(): void {
		$organizer = $this->admin;
		$other = $this->player('helper');
		$this->chatAdmins([$organizer, $other]);
		$this->newDraft($organizer, '23.11 20:00 | Кубок | 3 | 3,3,4');
		self::assertSame($organizer->id, $this->app->tournament_repo->find(1)->organizer_id);

		$this->confirmCard($this->player('vasya'));
		self::assertSame(TournamentStatus::DRAFT, $this->app->tournament_repo->find(1)->status);

		$this->dispatch($this->privateMessage($other, '/open 1'));
		self::assertStringContainsString('автор или главный админ', $this->http->lastText());
		self::assertSame(TournamentStatus::DRAFT, $this->app->tournament_repo->find(1)->status);

		$this->confirmCard($organizer);
		self::assertSame(TournamentStatus::REGISTRATION, $this->app->tournament_repo->find(1)->status);

		// /close removed
		try {
			$this->app->tournaments->closeRegistration($other->id, 1);
			self::fail('other must not close');
		} catch (\P1Bot\Domain\Exception\DomainError $e) {
			self::assertStringContainsString('автор или главный админ', $e->getMessage());
		}
		self::assertSame(TournamentStatus::REGISTRATION, $this->app->tournament_repo->find(1)->status);
	}

	public function testOwnerCanManageSomeoneElsesTournament(): void {
		$organizer = $this->admin;
		$this->chatAdmins([$organizer]);
		$this->newDraft($organizer, '23.11 20:00 | Кубок | 3 | 3,3,4');
		$this->confirmCard($organizer);
		$this->app->tournaments->closeRegistration($this->owner->id, 1);
		self::assertSame(TournamentStatus::CLOSED, $this->app->tournament_repo->find(1)->status);
	}

	public function testThirdAdminCannotConfirmSomeoneElsesCard(): void {
		$organizer = $this->admin;
		$third = $this->player('third');
		$this->chatAdmins([$organizer, $third]);
		$this->newDraft($organizer, '23.11 20:00 | Кубок | 3 | 3,3,4');
		$this->confirmCard($third);
		self::assertSame(TournamentStatus::DRAFT, $this->app->tournament_repo->find(1)->status);
	}

	/**
		@param list<\P1Bot\Domain\Model\Player> $admins Telegram chat administrators
	*/
	protected function chatAdmins(array $admins): void {
		$this->http->onTelegram('getChatAdministrators', function () use ($admins): array {
			return ['ok' => true, 'result' => array_map(fn ($p): array => ['status' => 'administrator', 'user' => [
				'id' => $this->tgIdOf($p),
				'is_bot' => false,
				'first_name' => $p->display_name,
				'username' => $p->username,
			]], $admins)];
		});
	}

	public function testOpenReopensClosedTournamentDirectly(): void {
		$this->newDraft($this->owner, '23.11 20:00 | Кубок | 3 | 3,3,4');
		$this->confirmCard($this->owner);
		$this->app->tournaments->closeRegistration($this->owner->id, 1);
		$this->dispatch($this->privateMessage($this->owner, '/open 1'));
		self::assertSame(TournamentStatus::REGISTRATION, $this->app->tournament_repo->find(1)->status);
	}

	/**
		Replies to the current card of tournament №1.
	*/
	protected function confirmCard(\P1Bot\Domain\Model\Player $from, string $text = 'да'): void {
		$this->dispatch($this->privateMessage($from, $text, ['reply_to_message' => [
			'message_id' => $this->app->tournament_repo->find(1)->card_msg_id,
			'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Bot'],
		]]));
	}

	public function testAmbiguousTournamentAsksForId(): void {
		$this->newDraft($this->owner, '23.11 20:00 | Первый | 3 | 3,3,4');
		$this->newDraft($this->owner, '24.11 20:00 | Второй | 3 | 3,3,4');
		$this->dispatch($this->privateMessage($this->owner, '/open'));
		self::assertStringContainsString('Уточни номер турнира', $this->http->lastText());
		self::assertStringContainsString('№2 — Второй', $this->http->lastText());
	}

	public function testLevelAndBalance(): void {
		$this->player('vasya');
		$this->dispatch($this->privateMessage($this->owner, '/level @vasya 4'));
		self::assertStringContainsString('@vasya — средний+ (4)', $this->http->lastText());

		$this->dispatch($this->privateMessage($this->owner, '/level @petya высокий +'));
		self::assertStringContainsString('@petya ещё не знаком со мной', $this->http->lastText());
		self::assertNull($this->app->players->findByUsername('petya'));

		$petya = $this->player('petya');
		$this->dispatch($this->privateMessage($this->owner, '/level @petya высокий +'));
		self::assertStringContainsString('@petya — высокий+ (6)', $this->http->lastText());
		self::assertSame(6, $this->app->balances->levelOf($this->app->balances->findDefault()->id, $petya->id));

		$this->dispatch($this->privateMessage($this->owner, '/level @vasya 9'));
		self::assertStringContainsString('Уровень от 1 до 6', $this->http->lastText());

		$this->dispatch($this->privateMessage($this->owner, '/players'));
		$all = $this->http->lastText();
		self::assertStringContainsString('<blockquote><b>средний+ (4)</b> — 1</blockquote>', $all);
		self::assertStringContainsString('<blockquote><b>высокий+ (6)</b> — 1</blockquote>', $all);
		self::assertStringContainsString("\n<a href=\"https://t.me/vasya\">@vasya</a>\n", $all);
		self::assertStringNotContainsString('lvl', $all, 'the level is in the header, not next to the name');
	}

	public function testNoServerLinksInTexts(): void {
		$this->dispatch($this->privateMessage($this->owner, '/help'));
		$this->newDraft($this->owner, '23.11 20:00 | Кубок | 3 | 3,3,4');
		$this->dispatch($this->privateMessage($this->owner, '/open'));
		$this->confirmCard($this->owner);
		self::assertSame(TournamentStatus::REGISTRATION, $this->app->tournament_repo->find(1)->status);
		foreach ($this->http->sent('sendMessage') as $message) {
			# Profile links t.me / tg:// are OK; forbid other http(s) (server paths, etc.).
			self::assertDoesNotMatchRegularExpression(
				'~https?://(?!(?:t\.me|telegram\.me)/)~',
				$message['text'],
			);
		}
	}

	public function testTournamentsListShowsLineupsAndDraftsForAdmins(): void {
		$this->newDraft($this->owner, '23.11 20:00 | Кубок <1> | 2 | 3,4');
		$this->confirmCard($this->owner);
		$vasya = $this->player('vasya');
		$this->http->reset();
		$this->dispatch($this->privateMessage($vasya, '/tournaments'));
		$empty = $this->http->lastText();
		self::assertStringStartsWith("📋 <b>Турниры</b>\n\n🏆 <b>Кубок &lt;1&gt;</b> · №1\n🗓 23.11.2026 ⏰ 20:00 МСК", $empty);
		self::assertStringContainsString('<i>Пока никто не записался.</i>', $empty);

		$cap = $this->player('cap');
		$this->app->teams->createTeam(1, $cap->id, 'Wolves & Co', [$this->player('mate')->id]);
		$this->app->teams->joinSolo(1, $this->player('solo')->id);
		$this->newDraft($this->owner, '24.11 21:00 | Черновой | 2 | 3,3');

		$this->http->reset();
		$this->dispatch($this->privateMessage($vasya, '/tournaments'));
		$text = $this->http->lastText();
		self::assertCount(1, $this->http->sent('sendMessage'));
		self::assertStringContainsString('🏆 <b>Кубок &lt;1&gt;</b> · №1', $text);
		self::assertStringContainsString('👤 Организатор: <a href="https://t.me/', $text);
		self::assertStringContainsString('🟢 Идёт регистрация', $text);
		self::assertStringContainsString('🎟 Занято 3/24 · запас 0', $text);
		self::assertStringContainsString("Wolves &amp; Co", $text);
		self::assertStringContainsString("@cap</a>", $text);
		self::assertStringContainsString("@mate</a>", $text);
		self::assertStringContainsString("🙋 <b>Ищут команду:</b>", $text);
		self::assertStringContainsString("@solo</a>", $text);
		self::assertStringNotContainsString('Черновой', $text, 'players do not see drafts');

		$this->dispatch($this->privateMessage($this->owner, '/tournaments'));
		$text = $this->http->lastText();
		self::assertStringContainsString("➖➖➖➖➖\n\n🏆 <b>Черновой</b> · №2", $text);
		self::assertStringContainsString('Черновик, его видишь только ты. Опубликовать: /open 2', $text);
	}

	public function testLongTournamentListIsSplitIntoMessages(): void {
		for ($i = 1; $i <= 3; $i++) {
			$this->newDraft($this->owner, sprintf('2%d.11 20:00 | Кубок %d | 4 | 3,3,3,3 | 200', $i, $i));
			$this->confirmCardOf($i);
			for ($k = 0; $k < 25; $k++) {
				$ids = [];
				for ($m = 0; $m < 3; $m++) {
					$ids[] = $this->player(sprintf('p%d_%d_%d_long_username', $i, $k, $m))->id;
				}
				$this->app->teams->createTeam(
					$i,
					$this->player(sprintf('cap%d_%d_long_username', $i, $k))->id,
					'Team ' . $i . '-' . $k,
					$ids,
				);
			}
		}
		$this->http->reset();
		$this->dispatch($this->privateMessage($this->player('vasya'), '/tournaments'));
		$sent = $this->http->sent('sendMessage');
		self::assertGreaterThan(1, count($sent));
		foreach ($sent as $message) {
			self::assertLessThanOrEqual(4096, mb_strlen($message['text']));
		}
		$all = implode("\n", array_column($sent, 'text'));
		foreach ([1, 2, 3] as $i) {
			self::assertStringContainsString('🏆 <b>Кубок ' . $i . '</b>', $all);
		}
	}

	protected function confirmCardOf(int $id): void {
		$this->dispatch($this->privateMessage($this->owner, 'да', ['reply_to_message' => [
			'message_id' => $this->app->tournament_repo->find($id)->card_msg_id,
			'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Bot'],
		]]));
	}

	public function testGlobalNumbersAreTournamentIds(): void {
		// Number = database id, same in every group; does not shift when another tournament is cancelled.
		$this->newDraft($this->owner, '25.11 20:00 | Поздний | 2 | 3,3');
		$this->confirmCardOf(1);
		$this->newDraft($this->owner, '23.11 20:00 | Ранний | 2 | 3,3');
		self::assertStringContainsString('Турнир №2 (черновик)', $this->http->lastText());
		$this->confirmCardOf(2);

		$later = $this->app->tournament_repo->find(1);
		self::assertStringContainsString('🏆 <b>Поздний</b> · №1', $this->app->announcer->text($later));

		$this->http->reset();
		$this->dispatch($this->privateMessage($this->owner, '/info 2'));
		self::assertStringContainsString('🏆 <b>Ранний</b> · №2', $this->http->lastText());
		$this->app->tournaments->edit($this->owner->id, 1, new \P1Bot\Domain\EditTournament(max_players: 30));
		self::assertSame(30, $this->app->tournament_repo->find(1)->max_players);

		$this->dispatch($this->privateMessage($this->owner, '/tournaments'));
		$list = $this->http->lastText();
		self::assertLessThan(strpos($list, 'Поздний'), strpos($list, 'Ранний'));

		$this->http->reset();
		$this->dispatch($this->press($this->owner, 'tcancely:2'));
		self::assertSame(TournamentStatus::CANCELLED, $this->app->tournament_repo->find(2)->status);
		self::assertStringContainsString('🏆 <b>Поздний</b> · №1', $this->app->announcer->text($this->app->tournaments->get(1)));
		$this->dispatch($this->privateMessage($this->owner, '/info 2'));
		self::assertStringContainsString('🏆 <b>Ранний</b> · №2', $this->http->lastText());
		self::assertStringContainsString('Отменён', $this->http->lastText());
	}

	public function testTeamSizeLockedMessageIsClear(): void {
		$this->newDraft($this->owner, '23.11 20:00 | Кубок | 3 | 10');
		$this->confirmCard($this->owner);
		$this->app->teams->joinSolo(1, $this->player('vasya')->id);
		try {
			$this->app->tournaments->edit($this->owner->id, 1, new \P1Bot\Domain\EditTournament(team_size: 2, reference_team: '8'));
			self::fail('team size is locked');
		} catch (\P1Bot\Domain\Exception\ValidationError $e) {
			self::assertStringContainsString('Размер команды уже не поменять: на турнир есть заявки', $e->getMessage());
			self::assertStringContainsString('Уровень турнира, название, время и лимит участников менять можно', $e->getMessage());
			self::assertStringNotContainsString('/cancel', $e->getMessage());
		}
		self::assertSame(3, $this->app->tournament_repo->find(1)->team_size);
		$this->app->tournaments->edit($this->owner->id, 1, new \P1Bot\Domain\EditTournament(reference_team: '12'));
		self::assertSame(12, $this->app->tournament_repo->find(1)->max_points, 'the level may still change');
		$error = (string) $this->newDraft($this->owner, '23.11 20:00 | Кубок | 3 | средний');
		self::assertStringContainsString('Укажи уровень каждого игрока: 3 игрока', $error);
	}

	public function testHelpContentsAndLevelsCommandIsGone(): void {
		$vasya = $this->player('vasya');
		$this->withoutSharedOwnerGroup($vasya);
		$this->dispatch($this->privateMessage($vasya, '/levels'));
		self::assertSame('Такой команды я не знаю 🤔 Подсказка: /help', $this->http->lastText());

		$this->dispatch($this->privateMessage($vasya, '/help'));
		$help_user = $this->http->lastText();
		foreach ([
			'/players — все игроки',
			'/history',
			'/nick — свой игровой ник',
			'<code>/nick игровой_ник</code>',
			'/team',
			'/tournaments 2 — состав одного турнира',
			'/help_level — Как считаются уровни и сила команды',
		] as $needle) {
			self::assertStringContainsString($needle, $help_user);
		}
		foreach ([
			'/levels', '/edit', '/cancel', '/level @user 4', '/nick @user', 'обычным языком',
			'запиши команду', '/info', '📊 <b>Уровни игроков</b>', 'Создание турниров', '/group',
		] as $needle) {
			self::assertStringNotContainsString($needle, $help_user);
		}

		$this->dispatch($this->privateMessage($vasya, '/help_level'));
		$levels = $this->http->lastText();
		foreach ([
			'1 — начальный (1 балл). Синонимы: базовый, начинающий',
			'5 — высокий (5 баллов). Синоним: танк',
			'6 — высокий+ (6 баллов)',
			'Игрок, которому ещё не поставили уровень, считается средним (3).',
			'⚖️ <b>Уровень турнира</b>',
			'К сумме я добавляю бонус',
			'📊 <b>Уровни игроков</b>',
		] as $needle) {
			self::assertStringContainsString($needle, $levels);
		}

		$this->everyoneIsGroupMember();
		$this->dispatch($this->groupMessage($vasya, '/help'));
		$help_group = $this->http->lastText();
		self::assertStringContainsString('/level — свой уровень', $help_group);
		self::assertStringContainsString('/tournaments 2 — состав турнира', $help_group);
		self::assertStringContainsString('/help_level — Как считаются уровни и сила команды', $help_group);
		self::assertStringNotContainsString('/levels', $help_group);
		self::assertStringNotContainsString('запиши меня', $help_group);
		self::assertStringNotContainsString('/new', $help_group);
		self::assertStringNotContainsString('/nick', $help_group);
		self::assertStringNotContainsString('/info', $help_group);
		self::assertStringNotContainsString('📊 <b>Уровни игроков</b>', $help_group);

		$this->dispatch($this->privateMessage($this->admin, '/help'));
		$admin_help = $this->http->lastText();
		self::assertStringContainsString('Создание турниров', $admin_help);
		self::assertStringContainsString('только автор турнира и главный админ', $admin_help);
		self::assertStringNotContainsString('Только для главного админа', $admin_help);

		$this->dispatch($this->privateMessage($this->owner, '/help'));
		$help = $this->http->lastText();
		self::assertStringContainsString('Только для главного админа', $help);
		self::assertStringContainsString('«✏️ Изменить турнир» и «❌ Отменить турнир»', $help);
		self::assertStringContainsString('«✏️ Поправить», «🗑 Удалить»', $help);
		self::assertStringNotContainsString('/edit', $help);
		self::assertStringNotContainsString('/cancel', $help);
		self::assertStringNotContainsString('/close', $help);
		self::assertStringContainsString('/nick @user НикВИгре', $help);
		self::assertStringContainsString('/level @user 4', $help);
		self::assertLessThan(4096, mb_strlen($help));
		self::assertStringNotContainsStringIgnoringCase('эталон', $help);

		$this->dispatch($this->privateMessage($vasya, '/players'));
		self::assertStringContainsString('📋 <b>Игроки</b>', $this->http->lastText());
	}

	public function testHistoryListsFinishedAndCancelled(): void {
		$a = $this->openTournament(3); // starts 14.11
		$b = $this->app->tournaments->create(new \P1Bot\Domain\CreateTournament(
			self::CHAT_ID,
			$this->owner->id,
			'Отменённый кубок',
			'2026-11-10 20:00',
			3,
			'3,3,4',
		));
		$b = $this->app->tournaments->openRegistration($this->owner->id, $b->id);
		$this->app->tournaments->cancel($this->owner->id, $b->id);
		$this->app->tournament_repo->update($a->id, ['status' => \P1Bot\Domain\TournamentStatus::FINISHED]);
		$this->http->reset();
		$this->dispatch($this->privateMessage($this->owner, '/history'));
		$text = $this->http->lastText();
		self::assertStringContainsString('Прошедшие турниры', $text);
		self::assertStringContainsString('Субботний кубок', $text);
		self::assertStringContainsString('Отменённый кубок', $text);
		self::assertStringContainsString('завершён', $text);
		self::assertStringContainsString('отменён', $text);
		// newest first by starts_at: 14.11 before 10.11
		self::assertLessThan(strpos($text, 'Отменённый кубок'), strpos($text, 'Субботний кубок'));
		self::assertStringContainsString('Состав: /tournaments N', $text);
		$this->dispatch($this->privateMessage($this->owner, '/info ' . $a->id));
		self::assertStringContainsString('Субботний кубок', $this->http->lastText());
		self::assertStringContainsString('№' . $a->id, $this->http->lastText());
		$this->dispatch($this->privateMessage($this->owner, '/tournaments ' . $a->id));
		self::assertStringContainsString('Субботний кубок', $this->http->lastText());
		self::assertStringContainsString('№' . $a->id, $this->http->lastText());
		$this->http->reset();
		$this->dispatch($this->privateMessage($this->owner, '/info'));
		$bare = implode("\n", array_column($this->http->sent('sendMessage'), 'text'));
		self::assertStringNotContainsString('Субботний кубок', $bare, 'bare /info lists only active tournaments');
		self::assertStringNotContainsString('Отменённый кубок', $bare);
	}

	public function testLevelViewOwnOtherAllAndGroupRedirect(): void {
		$vasya = $this->player('vasya');
		$petya = $this->player('petya');
		$this->dispatch($this->privateMessage($this->owner, '/level @vasya 5'));
		$this->dispatch($this->privateMessage($this->owner, '/level @petya 2'));
		$this->http->reset();

		$this->dispatch($this->privateMessage($vasya, '/level'));
		self::assertStringContainsString('Твой уровень: высокий (5)', $this->http->lastText());
		self::assertStringContainsString('Как устроены уровни — /help_level, все игроки — /players', $this->http->lastText());

		$this->dispatch($this->privateMessage($petya, '/level'));
		self::assertStringContainsString('Твой уровень: начальный+ (2)', $this->http->lastText());

		$unset = $this->player('newbie');
		$this->dispatch($this->privateMessage($unset, '/level'));
		self::assertStringContainsString('Уровень ещё не ставили — пока считаю средний (3)', $this->http->lastText());

		$this->dispatch($this->privateMessage($vasya, '/level @petya'));
		self::assertStringContainsString('@petya — начальный+ (2)', $this->http->lastText());

		$this->dispatch($this->privateMessage($vasya, '/level all'));
		$all = $this->http->lastText();
		self::assertStringContainsString('<a href="https://t.me/vasya">@vasya</a> — высокий (5)', $all);
		self::assertStringContainsString('<a href="https://t.me/petya">@petya</a> — начальный+ (2)', $all);
		// sorted by level desc: vasya before petya
		self::assertLessThan(strpos($all, '@petya'), strpos($all, '@vasya'));
		self::assertStringNotContainsString('Поставить:', $all);

		$this->dispatch($this->privateMessage($this->owner, '/level'));
		self::assertStringContainsString('Поставить: /level @ник 4', $this->http->lastText());

		// group: /level answers in chat; /level all redirects
		$this->http->reset();
		$this->dispatch($this->groupMessage($vasya, '/level'));
		self::assertStringContainsString('Твой уровень: высокий (5)', $this->http->lastText());
		$this->http->reset();
		$this->dispatch($this->groupMessage($vasya, '/level all'));
		$sent = $this->http->sent('sendMessage');
		self::assertNotSame([], $sent);
		$url = $sent[0]['reply_markup']['inline_keyboard'][0][0]['url'] ?? null;
		self::assertNotNull($url);
		self::assertStringContainsString('start=', $url);
	}
}
