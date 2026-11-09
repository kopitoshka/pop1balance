<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Domain\TournamentStatus;
use P1Bot\Support\DateFormat;
use P1Bot\Tests\Support\IntegrationTestCase;

class AdminCommandsTest extends IntegrationTestCase {

	public function testNewOneLine(): void {
		$this->dispatch($this->privateMessage($this->owner, '/new 23.11 20:00 | Кубок выходного дня | 3 | 3,3,4 | 30'));

		$t = $this->app->tournament_repo->find(1);
		self::assertNotNull($t);
		self::assertSame('Кубок выходного дня', $t->title);
		self::assertSame('2026-11-23 17:00:00', DateFormat::toDb($t->starts_at));
		self::assertSame(3, $t->team_size);
		self::assertSame(10, $t->max_points);
		self::assertSame(30, $t->max_players);
		self::assertSame($this->owner->id, $t->organizer_id);
		self::assertSame(TournamentStatus::DRAFT, $t->status);

		$card = $this->http->sent('sendMessage')[0];
		self::assertStringContainsString('Турнир №1 (черновик)', $card['text']);
		self::assertStringContainsString('⚖️ Уровень турнира: средний (10 баллов на троих)', $card['text']);
		self::assertStringContainsString('По игрокам: средний, средний, средний+', $card['text']);
		self::assertStringNotContainsStringIgnoringCase('эталон', $card['text']);
		self::assertStringEndsWith('Публикуем или что-то поправить?', $card['text']);
		self::assertSame('pub:1', $card['reply_markup']['inline_keyboard'][0][0]['callback_data']);
		self::assertSame('fix:1', $card['reply_markup']['inline_keyboard'][0][1]['callback_data']);
		self::assertSame(1000, $this->app->tournament_repo->find(1)->card_msg_id);
		self::assertSame([], $this->http->sent('editMessageText'), 'nothing published');
	}

	public function testNewRequiresReferenceTeamAndUsesDefaults(): void {
		foreach (['/new 23.11 20:00 | Без баланса | 2 | -', '/new 23.11 20:00 | Без баланса | 2'] as $command) {
			$this->dispatch($this->privateMessage($this->owner, $command));
			self::assertNull($this->app->tournament_repo->find(1), $command);
		}

		$this->dispatch($this->privateMessage($this->owner, '/new 23.11 20:00 | Пары | 2 | Танк, слабый плюс'));
		$t = $this->app->tournament_repo->find(1);
		self::assertSame(7, $t->max_points);
		self::assertNotNull($t->balance_id, 'default scale');
		self::assertSame(24, $t->max_players);
	}

	public function testClearYesReplyToCardPublishes(): void {
		$this->dispatch($this->privateMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | 3,3,4'));
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
		$this->dispatch($this->privateMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | 3,3,4'));
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

	public function testEditDraftShowsNewCardAndOldCardIsOutdated(): void {
		$this->dispatch($this->privateMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | 3,3,4'));
		$old_card = $this->app->tournament_repo->find(1)->card_msg_id;
		$this->http->reset();

		$this->dispatch($this->privateMessage($this->owner, '/edit 1 уровень средний, средний, высокий'));
		$t = $this->app->tournament_repo->find(1);
		self::assertSame(11, $t->max_points);
		self::assertNotSame($old_card, $t->card_msg_id);
		self::assertStringContainsString('Публикуем или что-то поправить?', $this->http->lastText());

		$this->dispatch($this->privateMessage($this->owner, 'да', ['reply_to_message' => [
			'message_id' => $old_card,
			'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Bot'],
		]]));
		self::assertSame(
			TournamentStatus::DRAFT,
			$this->app->tournament_repo->find(1)->status,
			'old card is not a confirmation',
		);
	}

	public function testNoBalanceEditIsGone(): void {
		$this->dispatch($this->privateMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | 3,3,4'));
		$this->dispatch($this->privateMessage($this->owner, '/edit 1 без баланса'));
		self::assertStringContainsString('Не знаю поле', $this->http->lastText());
		self::assertSame(10, $this->app->tournament_repo->find(1)->max_points);
	}

	public function testNewWithoutArgsExplainsSyntax(): void {
		$this->dispatch($this->privateMessage($this->owner, '/new'));
		self::assertStringContainsString('/new 23.11 20:00 | Название | 3 | 10 | 24', $this->http->lastText());
		self::assertStringContainsString('/levels', $this->http->lastText());
		self::assertNull($this->app->tournament_repo->find(1));
	}

	public function testValidationErrorIsShownHumanly(): void {
		$this->dispatch($this->privateMessage($this->owner, '/new 23.11 20:00 | Кубок | 5 | 3,3,3,3,3'));
		self::assertStringContainsString('от 2 до 4 игроков', $this->http->lastText());
	}

	public function testRegularPlayerCannotUseAdminCommands(): void {
		$vasya = $this->player('vasya');
		$this->withoutSharedOwnerGroup($vasya);
		$this->dispatch($this->privateMessage($vasya, '/new 23.11 20:00 | Кубок | 3'));
		self::assertStringContainsString('организатор P1', $this->http->lastText());
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
		$this->dispatch($this->privateMessage($this->admin, '/new 23.11 20:00 | Кубок | 2 | 3,3'));
		self::assertSame($this->admin->id, $this->app->tournament_repo->find(1)->organizer_id);
	}

	public function testOpenPostsAnnouncementWithButtons(): void {
		$this->dispatch($this->privateMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | 3,3,4'));
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
		self::assertSame(1002, $t->announce_msg_id, 'after the /new card and the /open card');
	}

	public function testOtherAdminCannotManageSomeoneElsesTournament(): void {
		$organizer = $this->admin;
		$other = $this->player('helper');
		$this->chatAdmins([$organizer, $other]);
		$this->dispatch($this->privateMessage($organizer, '/new 23.11 20:00 | Кубок | 3 | 3,3,4'));
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
		$this->dispatch($this->privateMessage($organizer, '/new 23.11 20:00 | Кубок | 3 | 3,3,4'));
		$this->confirmCard($organizer);
		$this->app->tournaments->closeRegistration($this->owner->id, 1);
		self::assertSame(TournamentStatus::CLOSED, $this->app->tournament_repo->find(1)->status);
	}

	public function testThirdAdminCannotConfirmSomeoneElsesCard(): void {
		$organizer = $this->admin;
		$third = $this->player('third');
		$this->chatAdmins([$organizer, $third]);
		$this->dispatch($this->privateMessage($organizer, '/new 23.11 20:00 | Кубок | 3 | 3,3,4'));
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

	public function testOpenDraftUnclearAnswerDoesNotPublish(): void {
		$this->dispatch($this->privateMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | 3,3,4'));
		$this->dispatch($this->privateMessage($this->owner, '/open 1'));
		$this->confirmCard($this->owner, 'да, только лимит 30');
		self::assertSame(TournamentStatus::DRAFT, $this->app->tournament_repo->find(1)->status);
		self::assertStringContainsString('Не публикую', $this->http->lastText());
	}

	public function testOpenReopensClosedTournamentDirectly(): void {
		$this->dispatch($this->privateMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | 3,3,4'));
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

	public function testEditCloseCancelRefreshAnnouncement(): void {
		$this->dispatch($this->privateMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | 3,3,4'));
		$this->confirmCard($this->owner);
		$this->http->reset();

		$this->dispatch($this->privateMessage($this->owner, '/edit 1 лимит 30'));
		self::assertSame(30, $this->app->tournament_repo->find(1)->max_players);
		$edits = $this->http->sent('editMessageText');
		self::assertCount(1, $edits);
		self::assertStringContainsString('Занято 0/30', $edits[0]['text']);

		$this->dispatch($this->privateMessage($this->owner, '/edit время 24.11 21:00'));
		self::assertSame('2026-11-24 18:00:00', DateFormat::toDb($this->app->tournament_repo->find(1)->starts_at));

		$this->dispatch($this->privateMessage($this->owner, '/edit 1 погода хорошая'));
		self::assertStringContainsString('Не знаю поле', $this->http->lastText());

		$this->app->tournaments->closeRegistration($this->owner->id, 1);
		self::assertSame(TournamentStatus::CLOSED, $this->app->tournament_repo->find(1)->status);
		$last = $this->http->sent('editMessageText');
		self::assertSame(
			'https://t.me/pop1_bot?start=t_1',
			end($last)['reply_markup']['inline_keyboard'][0][0]['url'],
			'questions still go to private',
		);

		$this->dispatch($this->privateMessage($this->owner, '/cancel 1'));
		self::assertSame(TournamentStatus::CANCELLED, $this->app->tournament_repo->find(1)->status);
	}

	public function testAmbiguousTournamentAsksForId(): void {
		$this->dispatch($this->privateMessage($this->owner, '/new 23.11 20:00 | Первый | 3 | 3,3,4'));
		$this->dispatch($this->privateMessage($this->owner, '/new 24.11 20:00 | Второй | 3 | 3,3,4'));
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

		$this->dispatch($this->privateMessage($this->owner, '/levels'));
		$all = implode("\n", array_column($this->http->sent('sendMessage'), 'text'));
		self::assertStringContainsString('Уровни игроков', $all);
		self::assertStringContainsString('@vasya', $all);
		self::assertStringContainsString('@petya', $all);
		self::assertStringContainsString('средний+', $all);
		self::assertStringContainsString('высокий+', $all);
		self::assertStringContainsString('Уровень турнира', $all);
	}

	public function testTournamentsListAndHelp(): void {
		$vasya = $this->player('vasya');
		$this->dispatch($this->privateMessage($vasya, '/tournaments'));
		self::assertStringContainsString('активных турниров нет', $this->http->lastText());

		$this->dispatch($this->privateMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | 3,3,4'));
		$this->confirmCard($this->owner);
		$this->dispatch($this->privateMessage($vasya, '/tournaments'));
		$list = $this->http->lastText();
		self::assertStringStartsWith("📋 <b>Турниры</b>\n\n🏆 <b>Кубок</b> · №1\n🗓 23.11.2026 ⏰ 20:00 МСК", $list);
		self::assertStringContainsString('<i>Пока никто не записался.</i>', $list);

		$this->withoutSharedOwnerGroup($vasya);
		$this->dispatch($this->privateMessage($vasya, '/help'));
		self::assertStringNotContainsString('Создание турниров', $this->http->lastText());
		$this->dispatch($this->privateMessage($this->owner, '/help'));
		self::assertStringContainsString('Создание турниров', $this->http->lastText());
	}

	public function testNoServerLinksInTexts(): void {
		$this->dispatch($this->privateMessage($this->owner, '/help'));
		$this->dispatch($this->privateMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | 3,3,4'));
		$this->dispatch($this->privateMessage($this->owner, '/open'));
		$this->confirmCard($this->owner);
		self::assertSame(TournamentStatus::REGISTRATION, $this->app->tournament_repo->find(1)->status);
		foreach ($this->http->sent('sendMessage') as $message) {
			self::assertDoesNotMatchRegularExpression('~https?://~', $message['text']);
		}
	}

	public function testTournamentsListShowsLineupsAndDraftsForAdmins(): void {
		$this->dispatch($this->privateMessage($this->owner, '/new 23.11 20:00 | Кубок <1> | 2 | 3,4'));
		$this->confirmCard($this->owner);
		$cap = $this->player('cap');
		$this->app->teams->createTeam(1, $cap->id, 'Wolves & Co', [$this->player('mate')->id]);
		$this->app->teams->joinSolo(1, $this->player('solo')->id);
		$this->dispatch($this->privateMessage($this->owner, '/new 24.11 21:00 | Черновой | 2 | 3,3'));

		$vasya = $this->player('vasya');
		$this->http->reset();
		$this->dispatch($this->privateMessage($vasya, '/tournaments'));
		$text = $this->http->lastText();
		self::assertCount(1, $this->http->sent('sendMessage'));
		self::assertStringContainsString('🏆 <b>Кубок &lt;1&gt;</b> · №1', $text);
		self::assertStringContainsString('👤 Организатор: <a href="tg://user?id=', $text);
		self::assertStringContainsString('🟢 Идёт регистрация', $text);
		self::assertStringContainsString('🎟 Занято 3/24 · запас 0', $text);
		self::assertStringContainsString("Wolves &amp; Co", $text);
		self::assertStringContainsString("@cap</a> (кэп)", $text);
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
			$this->dispatch($this->privateMessage(
				$this->owner,
				sprintf('/new 2%d.11 20:00 | Кубок %d | 4 | 3,3,3,3 | 200', $i, $i),
			));
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
		$this->dispatch($this->privateMessage($this->owner, '/new 25.11 20:00 | Поздний | 2 | 3,3'));
		$this->confirmCardOf(1);
		$this->dispatch($this->privateMessage($this->owner, '/new 23.11 20:00 | Ранний | 2 | 3,3'));
		self::assertStringContainsString('Турнир №2 (черновик)', $this->http->lastText());
		$this->confirmCardOf(2);

		$later = $this->app->tournament_repo->find(1);
		self::assertStringContainsString('🏆 <b>Поздний</b> · №1', $this->app->announcer->text($later));

		$this->http->reset();
		$this->dispatch($this->privateMessage($this->owner, '/info 2'));
		self::assertStringContainsString('🏆 <b>Ранний</b> · №2', $this->http->lastText());
		$this->dispatch($this->privateMessage($this->owner, '/edit 1 лимит 30'));
		self::assertSame(30, $this->app->tournament_repo->find(1)->max_players);
		self::assertStringContainsString('№1 Поздний', $this->http->lastText());

		$this->dispatch($this->privateMessage($this->owner, '/tournaments'));
		$list = $this->http->lastText();
		self::assertLessThan(strpos($list, 'Поздний'), strpos($list, 'Ранний'));

		$this->http->reset();
		$this->dispatch($this->privateMessage($this->owner, '/cancel 2'));
		self::assertSame(TournamentStatus::CANCELLED, $this->app->tournament_repo->find(2)->status);
		self::assertStringContainsString('🏆 <b>Поздний</b> · №1', $this->app->announcer->text($this->app->tournaments->get(1)));
		$this->dispatch($this->privateMessage($this->owner, '/info 2'));
		self::assertStringContainsString('🏆 <b>Ранний</b> · №2', $this->http->lastText());
		self::assertStringContainsString('Отменён', $this->http->lastText());
	}

	public function testEditLevelKeywordsAndValues(): void {
		$this->dispatch($this->privateMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | 10'));
		self::assertSame(10, $this->app->tournament_repo->find(1)->max_points);
		$cases = [
			'/edit 1 уровень 12' => 12,
			'/edit 1 уровень турнира 11' => 11,
			'/edit 1 максимальный уровень 9' => 9,
			'/edit 1 макс. уровень 13' => 13,
			'/edit 1 Макс уровень 3, 3, 5' => 11,
			'/edit 1 уровень средний, средний, высокий+' => 12,
		];
		foreach ($cases as $command => $points) {
			$this->dispatch($this->privateMessage($this->owner, $command));
			self::assertSame($points, $this->app->tournament_repo->find(1)->max_points, $command);
			self::assertStringContainsString('Публикуем или что-то поправить?', $this->http->lastText(), $command);
		}

		$this->dispatch($this->privateMessage($this->owner, '/edit 1 размер 2 уровень 7'));
		$t = $this->app->tournament_repo->find(1);
		self::assertSame([2, 7], [$t->team_size, $t->max_points]);
	}

	public function testInvalidLevelAsksAgainWithHelp(): void {
		$this->dispatch($this->privateMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | 10'));
		$bad = [
			'/edit 1 уровень' => 'Не указан уровень',
			'/edit 1 уровень средний' => 'уровней указано 1',
			'/edit 1 уровень 3, 3' => 'уровней указано 2',
			'/edit 1 уровень 19' => 'от 3 до 18',
			'/edit 1 уровень 3, 3, 7' => 'Уровня «7» нет',
			'/edit 1 уровень крутой, средний, средний' => 'Не понимаю уровень «крутой»',
			'/edit 1 размер 2' => 'укажи и уровень',
		];
		foreach ($bad as $command => $reason) {
			$this->dispatch($this->privateMessage($this->owner, $command));
			$text = $this->http->lastText();
			self::assertStringContainsString($reason, $text, $command);
			self::assertStringContainsString(
				"/edit 1 уровень 10\n/edit 1 уровень средний, средний, высокий",
				$text,
				$command,
			);
			self::assertStringContainsString('Все уровни и баллы: /levels', $text, $command);
			self::assertSame(10, $this->app->tournament_repo->find(1)->max_points, $command);
		}

		$this->dispatch($this->privateMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | средний'));
		$text = $this->http->lastText();
		self::assertStringContainsString('уровней указано 1', $text);
		self::assertStringContainsString('/new 23.11 20:00 | Кубок | 3 | 10 | 24', $text);
		self::assertStringContainsString('от 3 до 18 для команды из 3', $text);
		self::assertNull($this->app->tournament_repo->find(2));
	}

	public function testLevelLockedMessageIsClear(): void {
		$this->dispatch($this->privateMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | 10'));
		$this->confirmCard($this->owner);
		$this->app->teams->joinSolo(1, $this->player('vasya')->id);
		$this->dispatch($this->privateMessage($this->owner, '/edit 1 уровень 12'));
		self::assertStringContainsString(
			'Уровень турнира и размер команды уже не поменять: на турнир есть заявки',
			$this->http->lastText(),
		);
		self::assertStringContainsString('/cancel', $this->http->lastText());
		self::assertSame(10, $this->app->tournament_repo->find(1)->max_points);
	}

	public function testLevelsCommandAndHelp(): void {
		$vasya = $this->player('vasya');
		$this->dispatch($this->privateMessage($vasya, '/levels'));
		$text = $this->http->lastText();
		foreach ([
			'1 — начальный (1 балл). Синонимы: базовый, начинающий',
			'5 — высокий (5 баллов). Синоним: танк',
			'6 — высокий+ (6 баллов)',
			'Уровень турнира',
		] as $needle) {
			self::assertStringContainsString($needle, $text);
		}
		self::assertStringNotContainsString('/edit', $text);
		self::assertStringNotContainsString('Поставить уровень игроку', $text);

		$this->dispatch($this->groupMessage($this->owner, '/levels'));
		$group_levels = $this->http->lastText();
		self::assertStringContainsString('Уровни игроков', $group_levels);
		self::assertStringNotContainsString('/edit', $group_levels);
		self::assertStringNotContainsString('Поставить уровень игроку', $group_levels);

		$this->dispatch($this->privateMessage($this->owner, '/levels'));
		$owner_levels = implode("\n", array_column($this->http->sent('sendMessage'), 'text'));
		self::assertStringContainsString('/edit 1 уровень 10', $owner_levels);
		self::assertStringContainsString('Поставить уровень игроку: /level @user 4', $owner_levels);

		$this->dispatch($this->privateMessage($vasya, '/help'));
		$help_user = $this->http->lastText();
		self::assertStringContainsString('/levels', $help_user);
		self::assertStringContainsString('/history', $help_user);
		self::assertStringContainsString('/nick — свой игровой ник', $help_user);
		self::assertStringContainsString('/nick <ник>', $help_user);
		self::assertStringContainsString('/team', $help_user);
		self::assertStringNotContainsString('/level @user 4', $help_user);
		self::assertStringNotContainsString('/nick @user', $help_user);

		$this->dispatch($this->groupMessage($vasya, '/help'));
		$help_group = $this->http->lastText();
		self::assertStringContainsString('/level — свой уровень', $help_group);
		self::assertStringContainsString('/levels', $help_group);
		self::assertStringNotContainsString('/new', $help_group);
		self::assertStringNotContainsString('/nick', $help_group);
		self::assertStringNotContainsString('/level @user 4', $help_group);

		$this->dispatch($this->privateMessage($this->owner, '/help'));
		$help = $this->http->lastText();
		self::assertStringContainsString('/edit <id>', $help);
		self::assertStringContainsString('/cancel <id>', $help);
		self::assertStringNotContainsString('/close', $help);
		self::assertStringContainsString('/nick @user НикВИгре', $help);
		self::assertStringContainsString('/level @user 4', $help);
		foreach ([$help, $text, $owner_levels, $group_levels] as $chunk) {
			self::assertStringNotContainsStringIgnoringCase('эталон', $chunk);
		}
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
		$this->dispatch($this->privateMessage($this->owner, '/info ' . $a->id));
		self::assertStringContainsString('Субботний кубок', $this->http->lastText());
		self::assertStringContainsString('№' . $a->id, $this->http->lastText());
	}

	public function testLevelViewOwnOtherAllAndGroupRedirect(): void {
		$vasya = $this->player('vasya');
		$petya = $this->player('petya');
		$this->dispatch($this->privateMessage($this->owner, '/level @vasya 5'));
		$this->dispatch($this->privateMessage($this->owner, '/level @petya 2'));
		$this->http->reset();

		$this->dispatch($this->privateMessage($vasya, '/level'));
		self::assertStringContainsString('Твой уровень: высокий (5)', $this->http->lastText());
		self::assertStringContainsString('Все уровни — /levels', $this->http->lastText());

		$this->dispatch($this->privateMessage($petya, '/level'));
		self::assertStringContainsString('Твой уровень: начальный+ (2)', $this->http->lastText());

		$unset = $this->player('newbie');
		$this->dispatch($this->privateMessage($unset, '/level'));
		self::assertStringContainsString('Уровень ещё не ставили — пока считаю средний (3)', $this->http->lastText());

		$this->dispatch($this->privateMessage($vasya, '/level @petya'));
		self::assertStringContainsString('@petya — начальный+ (2)', $this->http->lastText());

		$this->dispatch($this->privateMessage($vasya, '/level all'));
		$all = $this->http->lastText();
		self::assertStringContainsString('@vasya — высокий (5)', $all);
		self::assertStringContainsString('@petya — начальный+ (2)', $all);
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
