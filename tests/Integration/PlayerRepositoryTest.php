<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Repository\ConversationRepository;
use P1Bot\Tests\Support\IntegrationTestCase;

final class PlayerRepositoryTest extends IntegrationTestCase
{
    public function testUsernamePlaceholderIsClaimedByRealAccount(): void
    {
        $placeholder = $this->app->players->ensureByUsername('@Vasya', $this->clock->now());
        self::assertNull($placeholder->tgUserId);
        self::assertSame('vasya', $placeholder->username);

        $real = $this->app->players->ensureByTelegram(777, 'vasya', 'Вася', $this->clock->now());
        self::assertSame($placeholder->id, $real->id);
        self::assertSame(777, $real->tgUserId);
        self::assertSame('Вася', $real->displayName);
    }

    public function testUsernameChangeUpdatesRow(): void
    {
        $p = $this->app->players->ensureByTelegram(778, 'old', 'Old', $this->clock->now());
        $p2 = $this->app->players->ensureByTelegram(778, 'new', 'New', $this->clock->now());
        self::assertSame($p->id, $p2->id);
        self::assertSame('@new', $p2->mention());
        self::assertNull($this->app->players->findByUsername('old'));
    }

    public function testConversationMemory(): void
    {
        $repo = new ConversationRepository($this->db);
        $id = $repo->save(null, self::CHAT_ID, $this->owner->id, 55, [['role' => 'user', 'content' => 'привет']], $this->clock->now()->modify('+10 minutes'));
        $found = $repo->findByBotMessage(self::CHAT_ID, 55, $this->clock->now());
        self::assertSame($id, $found['id']);
        self::assertSame('привет', $found['history'][0]['content']);

        $this->clock->advance('+11 minutes');
        self::assertNull($repo->findByBotMessage(self::CHAT_ID, 55, $this->clock->now()));
        self::assertSame(1, $repo->deleteExpired($this->clock->now()));
    }
}
