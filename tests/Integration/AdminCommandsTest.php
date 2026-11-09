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
        self::assertStringContainsString('Эталонная команда: средний, средний, средний+', $card['text']);
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

        $this->dispatch($this->groupMessage($this->owner, '/edit 1 эталон средний, средний, высокий'));
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
        self::assertStringContainsString('/new 23.11 20:00 | Название | 3 | средний, средний, средний+ | 24', $this->http->lastText());
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
        self::assertStringContainsString('23.11.2026 20:00 МСК', $announcement['text']);
        self::assertStringContainsString('средний (10 баллов на троих)', $announcement['text']);
        self::assertStringContainsString('Занято 0/24, резерв 0', $announcement['text']);
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
        self::assertStringContainsString('№1 Кубок — 23.11.2026 20:00 МСК, команды по 3', $this->http->lastText());

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
}
