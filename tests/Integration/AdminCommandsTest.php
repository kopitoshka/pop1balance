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
        self::assertStringContainsString('/open 1', $this->http->lastText());
    }

    public function testNewWithoutBalanceAndDefaults(): void
    {
        $this->dispatch($this->groupMessage($this->owner, '/new 23.11 20:00 | Без баланса | 2 | -'));
        $t = $this->app->tournamentRepo->find(1);
        self::assertNull($t->balanceId);
        self::assertSame(24, $t->maxPlayers);
    }

    public function testNewWithoutArgsExplainsSyntax(): void
    {
        $this->dispatch($this->groupMessage($this->owner, '/new'));
        self::assertStringContainsString('/new 23.11 20:00 | Название | 3 | 3,3,4 | 24', $this->http->lastText());
        self::assertNull($this->app->tournamentRepo->find(1));
    }

    public function testValidationErrorIsShownHumanly(): void
    {
        $this->dispatch($this->groupMessage($this->owner, '/new 23.11 20:00 | Кубок | 5'));
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
        $this->dispatch($this->groupMessage($this->admin, '/new 23.11 20:00 | Кубок | 2'));
        self::assertSame($this->admin->id, $this->app->tournamentRepo->find(1)->organizerId);
    }

    public function testOpenPostsAnnouncementWithButtons(): void
    {
        $this->dispatch($this->groupMessage($this->owner, '/new 23.11 20:00 | Кубок | 3 | 3,3,4'));
        $this->http->reset();
        $this->dispatch($this->groupMessage($this->owner, '/open'));

        $sent = $this->http->sent('sendMessage');
        self::assertCount(1, $sent);
        $announcement = $sent[0];
        self::assertArrayNotHasKey('reply_parameters', $announcement);
        self::assertStringContainsString('<b>Кубок</b>', $announcement['text']);
        self::assertStringContainsString('23.11.2026 20:00 МСК', $announcement['text']);
        self::assertStringContainsString('средний (10 баллов на троих)', $announcement['text']);
        self::assertStringContainsString('Занято 0/24, резерв 0', $announcement['text']);
        self::assertSame('solo:1', $announcement['reply_markup']['inline_keyboard'][0][0]['callback_data']);

        $t = $this->app->tournamentRepo->find(1);
        self::assertSame(TournamentStatus::REGISTRATION, $t->status);
        self::assertSame(1001, $t->announceMsgId, "second sendMessage (after the /new reply)");
    }

    public function testEditCloseCancelRefreshAnnouncement(): void
    {
        $this->dispatch($this->groupMessage($this->owner, '/new 23.11 20:00 | Кубок | 3'));
        $this->dispatch($this->groupMessage($this->owner, '/open 1'));
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
        $this->dispatch($this->groupMessage($this->owner, '/new 23.11 20:00 | Первый | 3'));
        $this->dispatch($this->groupMessage($this->owner, '/new 24.11 20:00 | Второй | 3'));
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
        $this->dispatch($this->groupMessage($this->owner, '/open'));
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
        $this->dispatch($this->groupMessage($this->owner, '/new 23.11 20:00 | Кубок | 3'));
        $this->dispatch($this->groupMessage($this->owner, '/open'));
        foreach ($this->http->sent('sendMessage') as $message) {
            self::assertDoesNotMatchRegularExpression('~https?://~', $message['text']);
        }
    }
}
