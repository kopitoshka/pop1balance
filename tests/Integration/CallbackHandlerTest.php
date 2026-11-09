<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Domain\Model\Player;
use P1Bot\Tests\Support\IntegrationTestCase;

final class CallbackHandlerTest extends IntegrationTestCase
{
    public function testSoloButtonsUpdateAnnouncement(): void
    {
        $t = $this->openTournament(2, null, 2);
        $this->app->announcer->publish($t->id);
        $this->http->reset();

        $vasya = $this->player('vasya');
        $this->dispatch($this->press($vasya, 'solo:' . $t->id));
        self::assertSame('main', $this->app->tournaments->lineup($t->id)->placementOf($vasya->id));
        $answer = $this->http->sent('answerCallbackQuery')[0];
        self::assertStringContainsString('Записал тебя соло', $answer['text']);
        self::assertTrue($answer['show_alert']);
        $edit = $this->http->sent('editMessageText')[0];
        self::assertStringContainsString("🙋 <b>Ищут команду:</b>\n@vasya", $edit['text']);

        $this->dispatch($this->press($vasya, 'solo:' . $t->id));
        self::assertStringContainsString('уже записан', $this->lastAnswer());

        $this->dispatch($this->press($this->player('a'), 'solo:' . $t->id));
        $this->dispatch($this->press($this->player('b'), 'solo:' . $t->id));
        self::assertStringContainsString('ты в резерве', $this->lastAnswer());

        $this->dispatch($this->press($vasya, 'unsolo:' . $t->id));
        self::assertStringContainsString('Снял тебя', $this->lastAnswer());
        self::assertNull($this->app->tournaments->lineup($t->id)->placementOf($vasya->id));
    }

    public function testHowtoAndForeignChat(): void
    {
        $t = $this->openTournament();
        $vasya = $this->player('vasya');
        $this->dispatch($this->press($vasya, 'howto:' . $t->id));
        self::assertStringContainsString('запиши команду', $this->lastAnswer());

        $raw = $this->press($vasya, 'solo:' . $t->id);
        $raw['callback_query']['message']['chat']['id'] = -999;
        $this->dispatch($raw);
        self::assertNull($this->app->tournaments->lineup($t->id)->placementOf($vasya->id));
    }

    public function testInfoCommandShowsLineup(): void
    {
        $t = $this->openTournament(2, null);
        $this->app->teams->createTeam($t->id, $this->player('cap')->id, 'Wolves', [$this->player('mate')->id]);
        $this->dispatch($this->groupMessage($this->player('vasya'), '/info'));
        self::assertStringContainsString('1. <b>Wolves</b> — 👑 @cap (кэп), @mate', $this->http->lastText());
    }

    public function testPublishAndFixButtonsOnDraftCard(): void
    {
        $this->dispatch($this->groupMessage($this->owner, '/new 23.11 20:00 | Кубок | 2 | 3,4'));
        $cardId = $this->app->tournamentRepo->find(1)->cardMsgId;

        // Someone else cannot publish or fix.
        $vasya = $this->player('vasya');
        $this->dispatch($this->press($vasya, 'pub:1', $cardId));
        self::assertStringContainsString('только его организатор', $this->lastAnswer());
        $this->dispatch($this->press($vasya, 'fix:1', $cardId));
        self::assertStringContainsString('только его организатор', $this->lastAnswer());
        self::assertSame('draft', $this->app->tournamentRepo->find(1)->status);

        // «Поправить» explains how to correct, publishes nothing.
        $this->dispatch($this->press($this->owner, 'fix:1', $cardId));
        self::assertStringContainsString('Ответь на карточку', $this->lastAnswer());
        self::assertSame('draft', $this->app->tournamentRepo->find(1)->status);

        // A button on an outdated card does nothing.
        $this->dispatch($this->press($this->owner, 'pub:1', $cardId - 1));
        self::assertStringContainsString('устарела', $this->lastAnswer());
        self::assertSame('draft', $this->app->tournamentRepo->find(1)->status);

        $this->dispatch($this->press($this->owner, 'pub:1', $cardId));
        self::assertStringContainsString('Опубликовано', $this->lastAnswer());
        $t = $this->app->tournamentRepo->find(1);
        self::assertSame('registration', $t->status);
        self::assertNotNull($t->announceMsgId);
        $edits = $this->http->sent('editMessageText');
        self::assertSame([], end($edits)['reply_markup']['inline_keyboard'], 'card buttons removed');

        $this->dispatch($this->press($this->owner, 'pub:1', $cardId));
        self::assertStringContainsString('уже не черновик', $this->lastAnswer());
    }

    /** @return array<string, mixed> */
    private function press(Player $from, string $data, int $messageId = 1): array
    {
        static $id = 1;
        return ['update_id' => 5000 + $id, 'callback_query' => [
            'id' => 'cb' . $id++,
            'from' => ['id' => $this->tgIdOf($from), 'is_bot' => false, 'first_name' => $from->displayName, 'username' => $from->username],
            'message' => ['message_id' => $messageId, 'chat' => ['id' => self::CHAT_ID, 'type' => 'supergroup', 'title' => 'P1 турниры']],
            'data' => $data,
        ]];
    }

    private function lastAnswer(): string
    {
        $answers = $this->http->sent('answerCallbackQuery');
        return (string) (end($answers)['text'] ?? '');
    }
}
