<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Domain\TournamentStatus;
use P1Bot\Support\DateFormat;
use P1Bot\Tests\Support\IntegrationTestCase;

final class AdminCommandsTest extends IntegrationTestCase
{
    public function testNewOneLine(): void
    {
        $this->dispatch($this->groupMessage($this->owner, '/new 23.11 20:00 | Кубок выходного дня | 3 | 3,3,4 | 30'));

        $t = $this->app->tournamentRepo->find(1);
        self::assertNotNull($t);
        self::assertSame('Кубок выходного дня', $t->title);
        self::assertSame('2026-11-23 17:00:00', DateFormat::toDb($t->startsAt));
        self::assertSame(3, $t->teamSize);
        self::assertSame(10, $t->maxPoints);
        self::assertSame(30, $t->maxPlayers);
        self::assertSame($this->owner->id, $t->organizerId);
        self::assertSame(TournamentStatus::DRAFT, $t->status);

        $card = $this->http->sent('sendMessage')[0];
        self::assertStringContainsString('Турнир №1 (черновик)', $card['text']);
        self::assertStringContainsString('⚖️ Уровень турнира: средний (10 баллов на троих)', $card['text']);
        self::assertStringContainsString('По игрокам: средний, средний, средний+', $card['text']);
        self::assertStringNotContainsStringIgnoringCase('эталон', $card['text']);
        self::assertStringEndsWith('Публикуем или что-то поправить?', $card['text']);
        self::assertSame('pub:1', $card['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        self::assertSame('fix:1', $card['reply_markup']['inline_keyboard'][0][1]['callback_data']);
        self::assertSame(1000, $this->app->tournamentRepo->find(1)->cardMsgId);
        self::assertSame([], $this->http->sent('editMessageText'), 'nothing published');
    }

    public function testNewRequiresReferenceTeamAndUsesDefaults(): void
    {
        foreach (['/new 23.11 20:00 | Без баланса | 2 | -', '/new 23.11 20:00 | Без баланса | 2'] as $command) {
            $this->dispatch($this->groupMessage($this->owner, $command));
            self::assertNull($this->app->tournamentRepo->find(1), $command);
        }

        $this->dispatch($this->groupMessage($this->owner, '/new 23.11 20:00 | Пары | 2 | Танк, слабый плюс'));
        $t = $this->app->tournamentRepo->find(1);
        self::assertSame(7, $t->maxPoints);
        self::assertNotNull($t->balanceId, 'default scale');
        self::assertSame(24, $t->maxPlayers);
    }

    public function testClearYesReplyToCardPublishes(): void
    {
        $this->dispatch($this->groupMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | 3,3,4'));
        $cardId = $this->app->tournamentRepo->find(1)->cardMsgId;

        $this->dispatch($this->groupMessage($this->owner, 'Да, всё верно!', ['reply_to_message' => [
            'message_id' => $cardId, 'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Bot'],
        ]]));
        $t = $this->app->tournamentRepo->find(1);
        self::assertSame(TournamentStatus::REGISTRATION, $t->status);
        self::assertNotNull($t->announceMsgId);
        $edit = $this->http->sent('editMessageText')[0];
        self::assertSame($cardId, $edit['message_id']);
        self::assertStringContainsString('Опубликовано', $edit['text']);
        self::assertStringNotContainsString('Публикуем или', $edit['text']);
        self::assertStringContainsString('Опубликовал', $this->http->lastText());
    }

    public function testUnclearReplyToCardDoesNotPublish(): void
    {
        $this->dispatch($this->groupMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | 3,3,4'));
        $cardId = $this->app->tournamentRepo->find(1)->cardMsgId;
        foreach (['да, но время 21:00', 'не, подожди', 'хм', 'вроде да?'] as $answer) {
            $this->dispatch($this->groupMessage($this->owner, $answer, ['reply_to_message' => [
                'message_id' => $cardId, 'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Bot'],
            ]]));
            self::assertSame(TournamentStatus::DRAFT, $this->app->tournamentRepo->find(1)->status, $answer);
            self::assertStringContainsString('Не публикую', $this->http->lastText());
        }
    }

    public function testEditDraftShowsNewCardAndOldCardIsOutdated(): void
    {
        $this->dispatch($this->groupMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | 3,3,4'));
        $oldCard = $this->app->tournamentRepo->find(1)->cardMsgId;
        $this->http->reset();

        $this->dispatch($this->groupMessage($this->owner, '/edit 1 уровень средний, средний, высокий'));
        $t = $this->app->tournamentRepo->find(1);
        self::assertSame(11, $t->maxPoints);
        self::assertNotSame($oldCard, $t->cardMsgId);
        self::assertStringContainsString('Публикуем или что-то поправить?', $this->http->lastText());

        $this->dispatch($this->groupMessage($this->owner, 'да', ['reply_to_message' => [
            'message_id' => $oldCard, 'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Bot'],
        ]]));
        self::assertSame(TournamentStatus::DRAFT, $this->app->tournamentRepo->find(1)->status, 'old card is not a confirmation');
    }

    public function testNoBalanceEditIsGone(): void
    {
        $this->dispatch($this->groupMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | 3,3,4'));
        $this->dispatch($this->groupMessage($this->owner, '/edit 1 без баланса'));
        self::assertStringContainsString('Не знаю поле', $this->http->lastText());
        self::assertSame(10, $this->app->tournamentRepo->find(1)->maxPoints);
    }

    public function testNewWithoutArgsExplainsSyntax(): void
    {
        $this->dispatch($this->groupMessage($this->owner, '/new'));
        self::assertStringContainsString('/new 23.11 20:00 | Название | 3 | 10 | 24', $this->http->lastText());
        self::assertStringContainsString('/levels', $this->http->lastText());
        self::assertNull($this->app->tournamentRepo->find(1));
    }

    public function testValidationErrorIsShownHumanly(): void
    {
        $this->dispatch($this->groupMessage($this->owner, '/new 23.11 20:00 | Кубок | 5 | 3,3,3,3,3'));
        self::assertStringContainsString('от 2 до 4 игроков', $this->http->lastText());
    }

    public function testRegularPlayerCannotUseAdminCommands(): void
    {
        $vasya = $this->player('vasya');
        $this->dispatch($this->groupMessage($vasya, '/new 23.11 20:00 | Кубок | 3'));
        self::assertStringContainsString('только для админов', $this->http->lastText());
        self::assertNull($this->app->tournamentRepo->find(1));
    }

    public function testTelegramChatAdminCanCreate(): void
    {
        $this->http->onTelegram('getChatAdministrators', function (): array {
            return ['ok' => true, 'result' => [['status' => 'administrator', 'user' => [
                'id' => $this->tgIdOf($this->admin), 'is_bot' => false, 'first_name' => 'Admin', 'username' => 'admin2',
            ]]]];
        });
        $this->dispatch($this->groupMessage($this->admin, '/new 23.11 20:00 | Кубок | 2 | 3,3'));
        self::assertSame($this->admin->id, $this->app->tournamentRepo->find(1)->organizerId);
    }

    public function testOpenPostsAnnouncementWithButtons(): void
    {
        $this->dispatch($this->groupMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | 3,3,4'));
        $this->http->reset();
        $this->dispatch($this->groupMessage($this->owner, '/open'));

        // /open on a draft does not publish: it shows the card and asks.
        $sent = $this->http->sent('sendMessage');
        self::assertCount(1, $sent);
        self::assertStringEndsWith('Публикуем или что-то поправить?', $sent[0]['text']);
        self::assertSame('pub:1', $sent[0]['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        self::assertSame(TournamentStatus::DRAFT, $this->app->tournamentRepo->find(1)->status);
        self::assertSame(1001, $this->app->tournamentRepo->find(1)->cardMsgId);

        $this->http->reset();
        $this->confirmCard($this->owner);
        $sent = $this->http->sent('sendMessage');
        self::assertCount(2, $sent, 'announcement + short reply');
        $announcement = $sent[0];
        self::assertArrayNotHasKey('reply_parameters', $announcement);
        self::assertStringContainsString('<b>Кубок</b>', $announcement['text']);
        self::assertStringContainsString('🗓 23.11.2026 ⏰ 20:00 МСК', $announcement['text']);
        self::assertStringContainsString('средний (10 баллов на троих)', $announcement['text']);
        self::assertStringContainsString('Занято 0/24 · резерв 0', $announcement['text']);
        self::assertSame('solo:1', $announcement['reply_markup']['inline_keyboard'][0][0]['callback_data']);

        $t = $this->app->tournamentRepo->find(1);
        self::assertSame(TournamentStatus::REGISTRATION, $t->status);
        self::assertSame(1002, $t->announceMsgId, 'after the /new card and the /open card');
    }

    public function testOpenByAnotherAdminLetsThemOrTheOrganizerConfirm(): void
    {
        $organizer = $this->admin;
        $other = $this->player('helper');
        $this->chatAdmins([$organizer, $other]);
        $this->dispatch($this->groupMessage($organizer, '/new 23.11 20:00 | Кубок | 3 | 3,3,4'));
        self::assertSame($organizer->id, $this->app->tournamentRepo->find(1)->organizerId);

        // A regular player can neither confirm by reply nor by the button.
        $this->confirmCard($this->player('vasya'));
        self::assertSame(TournamentStatus::DRAFT, $this->app->tournamentRepo->find(1)->status);

        // Another admin runs /open: a fresh card for them; now they may confirm.
        $this->dispatch($this->groupMessage($other, '/open 1'));
        $t = $this->app->tournamentRepo->find(1);
        self::assertSame($other->id, $t->cardShownTo);
        self::assertSame(TournamentStatus::DRAFT, $t->status);
        $this->confirmCard($other, 'давай, публикуем');
        self::assertSame(TournamentStatus::REGISTRATION, $this->app->tournamentRepo->find(1)->status);
    }

    public function testOrganizerMayConfirmCardShownToAnotherAdmin(): void
    {
        $organizer = $this->admin;
        $other = $this->player('helper');
        $this->chatAdmins([$organizer, $other]);
        $this->dispatch($this->groupMessage($organizer, '/new 23.11 20:00 | Кубок | 3 | 3,3,4'));
        $this->dispatch($this->groupMessage($other, '/open'));
        $this->confirmCard($organizer, 'ок');
        self::assertSame(TournamentStatus::REGISTRATION, $this->app->tournamentRepo->find(1)->status);
    }

    public function testThirdAdminCannotConfirmSomeoneElsesCard(): void
    {
        $organizer = $this->admin;
        $third = $this->player('third');
        $this->chatAdmins([$organizer, $third]);
        $this->dispatch($this->groupMessage($organizer, '/new 23.11 20:00 | Кубок | 3 | 3,3,4'));
        $this->confirmCard($third);
        self::assertSame(TournamentStatus::DRAFT, $this->app->tournamentRepo->find(1)->status);
    }

    /** @param list<\P1Bot\Domain\Model\Player> $admins Telegram chat administrators */
    private function chatAdmins(array $admins): void
    {
        $this->http->onTelegram('getChatAdministrators', function () use ($admins): array {
            return ['ok' => true, 'result' => array_map(fn ($p): array => ['status' => 'administrator', 'user' => [
                'id' => $this->tgIdOf($p), 'is_bot' => false, 'first_name' => $p->displayName, 'username' => $p->username,
            ]], $admins)];
        });
    }

    public function testOpenDraftUnclearAnswerDoesNotPublish(): void
    {
        $this->dispatch($this->groupMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | 3,3,4'));
        $this->dispatch($this->groupMessage($this->owner, '/open 1'));
        $this->confirmCard($this->owner, 'да, только лимит 30');
        self::assertSame(TournamentStatus::DRAFT, $this->app->tournamentRepo->find(1)->status);
        self::assertStringContainsString('Не публикую', $this->http->lastText());
    }

    public function testOpenReopensClosedTournamentDirectly(): void
    {
        $this->dispatch($this->groupMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | 3,3,4'));
        $this->confirmCard($this->owner);
        $this->dispatch($this->groupMessage($this->owner, '/close 1'));
        $this->dispatch($this->groupMessage($this->owner, '/open 1'));
        self::assertSame(TournamentStatus::REGISTRATION, $this->app->tournamentRepo->find(1)->status);
    }

    /** Replies to the current card of tournament №1. */
    private function confirmCard(\P1Bot\Domain\Model\Player $from, string $text = 'да'): void
    {
        $this->dispatch($this->groupMessage($from, $text, ['reply_to_message' => [
            'message_id' => $this->app->tournamentRepo->find(1)->cardMsgId,
            'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Bot'],
        ]]));
    }

    public function testEditCloseCancelRefreshAnnouncement(): void
    {
        $this->dispatch($this->groupMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | 3,3,4'));
        $this->confirmCard($this->owner);
        $this->http->reset();

        $this->dispatch($this->groupMessage($this->owner, '/edit 1 лимит 30'));
        self::assertSame(30, $this->app->tournamentRepo->find(1)->maxPlayers);
        $edits = $this->http->sent('editMessageText');
        self::assertCount(1, $edits);
        self::assertStringContainsString('Занято 0/30', $edits[0]['text']);

        $this->dispatch($this->groupMessage($this->owner, '/edit время 24.11 21:00'));
        self::assertSame('2026-11-24 18:00:00', DateFormat::toDb($this->app->tournamentRepo->find(1)->startsAt));

        $this->dispatch($this->groupMessage($this->owner, '/edit 1 погода хорошая'));
        self::assertStringContainsString('Не знаю поле', $this->http->lastText());

        $this->dispatch($this->groupMessage($this->owner, '/close 1'));
        self::assertSame(TournamentStatus::CLOSED, $this->app->tournamentRepo->find(1)->status);
        $last = $this->http->sent('editMessageText');
        self::assertSame([], end($last)['reply_markup']['inline_keyboard'], 'buttons removed');

        $this->dispatch($this->groupMessage($this->owner, '/cancel 1'));
        self::assertSame(TournamentStatus::CANCELLED, $this->app->tournamentRepo->find(1)->status);
    }

    public function testAmbiguousTournamentAsksForId(): void
    {
        $this->dispatch($this->groupMessage($this->owner, '/new 23.11 20:00 | Первый | 3 | 3,3,4'));
        $this->dispatch($this->groupMessage($this->owner, '/new 24.11 20:00 | Второй | 3 | 3,3,4'));
        $this->dispatch($this->groupMessage($this->owner, '/open'));
        self::assertStringContainsString('Уточни номер турнира', $this->http->lastText());
        self::assertStringContainsString('№2 — Второй', $this->http->lastText());
    }

    public function testLevelAndBalance(): void
    {
        $this->player('vasya');
        $this->dispatch($this->groupMessage($this->owner, '/level @vasya 4'));
        self::assertStringContainsString('@vasya — средний+ (4)', $this->http->lastText());

        $this->dispatch($this->groupMessage($this->owner, '/level @petya высокий +'));
        $petya = $this->app->players->findByUsername('petya');
        self::assertNotNull($petya, 'placeholder created for unknown @username');
        self::assertSame(6, $this->app->balances->levelOf($this->app->balances->findDefault()->id, $petya->id));

        $this->dispatch($this->groupMessage($this->owner, '/level @vasya 9'));
        self::assertStringContainsString('Уровень от 1 до 6', $this->http->lastText());

        $this->dispatch($this->groupMessage($this->owner, '/balance'));
        $text = $this->http->lastText();
        self::assertStringContainsString('Шкала «Баланс по умолчанию»', $text);
        self::assertStringContainsString('@vasya — средний+ (4)', $text);
        self::assertStringContainsString('@petya — высокий+ (6)', $text);
    }

    public function testTournamentsListAndHelp(): void
    {
        $vasya = $this->player('vasya');
        $this->dispatch($this->groupMessage($vasya, '/tournaments'));
        self::assertStringContainsString('активных турниров нет', $this->http->lastText());

        $this->dispatch($this->groupMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | 3,3,4'));
        $this->confirmCard($this->owner);
        $this->dispatch($this->groupMessage($vasya, '/tournaments'));
        $list = $this->http->lastText();
        self::assertStringStartsWith("📋 <b>Турниры</b>\n\n🏆 <b>Кубок</b> · №1\n🗓 23.11.2026 ⏰ 20:00 МСК", $list);
        self::assertStringContainsString('<i>Пока никто не записался.</i>', $list);

        $this->dispatch($this->groupMessage($vasya, '/help'));
        self::assertStringNotContainsString('Для админов', $this->http->lastText());
        $this->dispatch($this->groupMessage($this->owner, '/help'));
        self::assertStringContainsString('Для админов', $this->http->lastText());
    }

    public function testNoServerLinksInTexts(): void
    {
        $this->dispatch($this->groupMessage($this->owner, '/help'));
        $this->dispatch($this->groupMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | 3,3,4'));
        $this->dispatch($this->groupMessage($this->owner, '/open'));
        $this->confirmCard($this->owner);
        self::assertSame(TournamentStatus::REGISTRATION, $this->app->tournamentRepo->find(1)->status);
        foreach ($this->http->sent('sendMessage') as $message) {
            self::assertDoesNotMatchRegularExpression('~https?://~', $message['text']);
        }
    }

    public function testTournamentsListShowsLineupsAndDraftsForAdmins(): void
    {
        $this->dispatch($this->groupMessage($this->owner, '/new 23.11 20:00 | Кубок <1> | 2 | 3,4'));
        $this->confirmCard($this->owner);
        $cap = $this->player('cap');
        $this->app->teams->createTeam(1, $cap->id, 'Wolves & Co', [$this->player('mate')->id]);
        $this->app->teams->joinSolo(1, $this->player('solo')->id);
        $this->dispatch($this->groupMessage($this->owner, '/new 24.11 21:00 | Черновой | 2 | 3,3'));

        $vasya = $this->player('vasya');
        $this->http->reset();
        $this->dispatch($this->groupMessage($vasya, '/tournaments'));
        $text = $this->http->lastText();
        self::assertCount(1, $this->http->sent('sendMessage'));
        self::assertStringContainsString('🏆 <b>Кубок &lt;1&gt;</b> · №1', $text);
        self::assertStringContainsString('👤 Организатор: @andrey', $text);
        self::assertStringContainsString('🟢 Идёт регистрация', $text);
        self::assertStringContainsString('🎟 Занято 3/24 · резерв 0', $text);
        self::assertStringContainsString("🛡 <b>Команды:</b>\n1. <b>Wolves &amp; Co</b> — 👑 @cap (кэп), @mate", $text);
        self::assertStringContainsString("🙋 <b>Ищут команду:</b>\n@solo", $text);
        self::assertStringNotContainsString('Черновой', $text, 'players do not see drafts');

        $this->dispatch($this->groupMessage($this->owner, '/tournaments'));
        $text = $this->http->lastText();
        self::assertStringContainsString("➖➖➖➖➖\n\n🏆 <b>Черновой</b> · №2", $text);
        self::assertStringContainsString('Черновик. Опубликовать: /open 2', $text);
    }

    public function testLongTournamentListIsSplitIntoMessages(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->dispatch($this->groupMessage($this->owner, sprintf('/new 2%d.11 20:00 | Кубок %d | 4 | 3,3,3,3 | 200', $i, $i)));
            $this->confirmCardOf($i);
            for ($k = 0; $k < 25; $k++) {
                $ids = [];
                for ($m = 0; $m < 3; $m++) {
                    $ids[] = $this->player(sprintf('p%d_%d_%d_long_username', $i, $k, $m))->id;
                }
                $this->app->teams->createTeam($i, $this->player(sprintf('cap%d_%d_long_username', $i, $k))->id, 'Team ' . $i . '-' . $k, $ids);
            }
        }
        $this->http->reset();
        $this->dispatch($this->groupMessage($this->player('vasya'), '/tournaments'));
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

    private function confirmCardOf(int $id): void
    {
        $this->dispatch($this->groupMessage($this->owner, 'да', ['reply_to_message' => [
            'message_id' => $this->app->tournamentRepo->find($id)->cardMsgId,
            'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Bot'],
        ]]));
    }

    public function testShortNumbersFollowStartOrderAndShift(): void
    {
        // Real ids: 1 = later tournament, 2 = earlier one. Users see №1 = earlier, №2 = later.
        $this->dispatch($this->groupMessage($this->owner, '/new 25.11 20:00 | Поздний | 2 | 3,3'));
        $this->confirmCardOf(1);
        $this->dispatch($this->groupMessage($this->owner, '/new 23.11 20:00 | Ранний | 2 | 3,3'));
        self::assertStringContainsString('Турнир №1 (черновик)', $this->http->lastText());
        $this->confirmCardOf(2);

        $later = $this->app->tournamentRepo->find(1);
        $edits = array_values(array_filter($this->http->sent('editMessageText'), static fn (array $e): bool => $e['message_id'] === $later->announceMsgId));
        self::assertStringContainsString('🏆 <b>Поздний</b> · №2', end($edits)['text'], 'the older announcement was renumbered');

        $this->http->reset();
        $this->dispatch($this->groupMessage($this->owner, '/info 1'));
        self::assertStringContainsString('🏆 <b>Ранний</b> · №1', $this->http->lastText());
        $this->dispatch($this->groupMessage($this->owner, '/edit 2 лимит 30'));
        self::assertSame(30, $this->app->tournamentRepo->find(1)->maxPlayers, '№2 is real id 1');
        self::assertStringContainsString('№2 Поздний', $this->http->lastText());

        $this->dispatch($this->groupMessage($this->owner, '/tournaments'));
        $list = $this->http->lastText();
        self::assertLessThan(strpos($list, 'Поздний'), strpos($list, 'Ранний'));

        // Cancelling №1 shifts the later one to №1 and refreshes its announcement.
        $this->http->reset();
        $this->dispatch($this->groupMessage($this->owner, '/cancel 1'));
        self::assertSame(TournamentStatus::CANCELLED, $this->app->tournamentRepo->find(2)->status);
        $edits = array_values(array_filter($this->http->sent('editMessageText'), static fn (array $e): bool => $e['message_id'] === $later->announceMsgId));
        self::assertStringContainsString('🏆 <b>Поздний</b> · №1', end($edits)['text']);
        $this->dispatch($this->groupMessage($this->owner, '/info 2'));
        self::assertStringContainsString('Турнира №2 сейчас нет', $this->http->lastText());
    }

    public function testEditLevelKeywordsAndValues(): void
    {
        $this->dispatch($this->groupMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | 10'));
        self::assertSame(10, $this->app->tournamentRepo->find(1)->maxPoints);
        $cases = [
            '/edit 1 уровень 12' => 12,
            '/edit 1 уровень турнира 11' => 11,
            '/edit 1 максимальный уровень 9' => 9,
            '/edit 1 макс. уровень 13' => 13,
            '/edit 1 Макс уровень 3, 3, 5' => 11,
            '/edit 1 уровень средний, средний, высокий+' => 12,
        ];
        foreach ($cases as $command => $points) {
            $this->dispatch($this->groupMessage($this->owner, $command));
            self::assertSame($points, $this->app->tournamentRepo->find(1)->maxPoints, $command);
            self::assertStringContainsString('Публикуем или что-то поправить?', $this->http->lastText(), $command);
        }

        $this->dispatch($this->groupMessage($this->owner, '/edit 1 размер 2 уровень 7'));
        $t = $this->app->tournamentRepo->find(1);
        self::assertSame([2, 7], [$t->teamSize, $t->maxPoints]);
    }

    public function testInvalidLevelAsksAgainWithHelp(): void
    {
        $this->dispatch($this->groupMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | 10'));
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
            $this->dispatch($this->groupMessage($this->owner, $command));
            $text = $this->http->lastText();
            self::assertStringContainsString($reason, $text, $command);
            self::assertStringContainsString("/edit 1 уровень 10\n/edit 1 уровень средний, средний, высокий", $text, $command);
            self::assertStringContainsString('Все уровни и баллы: /levels', $text, $command);
            self::assertSame(10, $this->app->tournamentRepo->find(1)->maxPoints, $command);
        }

        $this->dispatch($this->groupMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | средний'));
        $text = $this->http->lastText();
        self::assertStringContainsString('уровней указано 1', $text);
        self::assertStringContainsString('/new 23.11 20:00 | Кубок | 3 | 10 | 24', $text);
        self::assertStringContainsString('от 3 до 18 для команды из 3', $text);
        self::assertNull($this->app->tournamentRepo->find(2));
    }

    public function testLevelLockedMessageIsClear(): void
    {
        $this->dispatch($this->groupMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | 10'));
        $this->confirmCard($this->owner);
        $this->app->teams->joinSolo(1, $this->player('vasya')->id);
        $this->dispatch($this->groupMessage($this->owner, '/edit 1 уровень 12'));
        self::assertStringContainsString('Уровень турнира и размер команды уже не поменять: на турнир есть заявки', $this->http->lastText());
        self::assertStringContainsString('/cancel', $this->http->lastText());
        self::assertSame(10, $this->app->tournamentRepo->find(1)->maxPoints);
    }

    public function testLevelsCommandAndHelp(): void
    {
        $vasya = $this->player('vasya');
        $this->dispatch($this->groupMessage($vasya, '/levels'));
        $text = $this->http->lastText();
        foreach (['1 — начальный (1 балл). Синонимы: слабый, базовый, начинающий', '5 — высокий (5 баллов). Синоним: танк', '6 — высокий+ (6 баллов)', '/edit 1 уровень 10'] as $needle) {
            self::assertStringContainsString($needle, $text);
        }
        $this->dispatch($this->groupMessage($vasya, '/help'));
        self::assertStringContainsString('/levels', $this->http->lastText());
        $this->dispatch($this->groupMessage($this->owner, '/help'));
        $help = $this->http->lastText();
        self::assertStringContainsString('/edit 1 уровень 10', $help);
        foreach ([$help, $text] as $t) {
            self::assertStringNotContainsStringIgnoringCase('эталон', $t);
        }
    }
}
