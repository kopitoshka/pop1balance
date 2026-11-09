<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Levels;
use P1Bot\Tests\Support\IntegrationTestCase;

final class BalanceServiceTest extends IntegrationTestCase
{
    public function testDefaultScaleBelongsToOwnerAndIsCreatedOnce(): void
    {
        $default = $this->app->balances->findDefault();
        self::assertNotNull($default);
        self::assertSame($this->owner->id, $default->ownerId);
        self::assertTrue($default->isDefault);
        self::assertSame($default->id, $this->app->balances->ensureDefault($this->owner->id)->id);
    }

    public function testUnknownPlayerIsAverage(): void
    {
        $vasya = $this->player('vasya');
        $default = $this->app->balances->findDefault();
        self::assertSame(Levels::DEFAULT, $this->app->balances->levelOf($default->id, $vasya->id));
        self::assertSame(Levels::DEFAULT, $this->app->balances->levelOf(null, $vasya->id));
    }

    public function testOwnerRatesInDefaultScale(): void
    {
        $vasya = $this->player('vasya');
        $scale = $this->app->balances->setLevel(self::CHAT_ID, $this->owner->id, $vasya->id, 5);
        self::assertTrue($scale->isDefault);
        self::assertSame(5, $this->app->balances->levelOf($scale->id, $vasya->id));
    }

    public function testAdminGetsOwnInheritedScaleAndOverridesLevels(): void
    {
        $vasya = $this->player('vasya');
        $petya = $this->player('petya');
        $this->rate($vasya, 5);
        $this->rate($petya, 2);

        $adminScale = $this->app->balances->setLevel(self::CHAT_ID, $this->admin->id, $vasya->id, 4);
        $default = $this->app->balances->findDefault();

        self::assertFalse($adminScale->isDefault);
        self::assertSame($this->admin->id, $adminScale->ownerId);
        self::assertSame($default->id, $adminScale->parentId);
        self::assertSame(4, $this->app->balances->levelOf($adminScale->id, $vasya->id), 'own rating wins');
        self::assertSame(2, $this->app->balances->levelOf($adminScale->id, $petya->id), 'inherited from parent');
        self::assertSame(5, $this->app->balances->levelOf($default->id, $vasya->id), 'parent untouched');

        $ratings = $this->app->balances->effectiveRatings($adminScale->id);
        self::assertSame(['level' => 4, 'balance_id' => $adminScale->id], $ratings[$vasya->id]);
        self::assertSame(['level' => 2, 'balance_id' => $default->id], $ratings[$petya->id]);

        $this->app->balances->resetLevel(self::CHAT_ID, $this->admin->id, $vasya->id, $adminScale->id);
        self::assertSame(5, $this->app->balances->levelOf($adminScale->id, $vasya->id));
    }

    public function testAdminCannotEditSomeoneElsesScale(): void
    {
        $vasya = $this->player('vasya');
        $default = $this->app->balances->findDefault();
        $this->expectException(PermissionDenied::class);
        $this->app->balances->setLevel(self::CHAT_ID, $this->admin->id, $vasya->id, 4, $default->id);
    }

    public function testRegularPlayerCannotRate(): void
    {
        $vasya = $this->player('vasya');
        $this->expectException(PermissionDenied::class);
        $this->app->balances->setLevel(self::CHAT_ID, $vasya->id, $vasya->id, 6);
    }

    public function testLevelRange(): void
    {
        $vasya = $this->player('vasya');
        $this->expectException(ValidationError::class);
        $this->rate($vasya, 7);
    }

    public function testCreateScaleWithoutParent(): void
    {
        $vasya = $this->player('vasya');
        $this->rate($vasya, 6);
        $scale = $this->app->balances->create(self::CHAT_ID, $this->admin->id, 'Своя шкала');
        self::assertNull($scale->parentId);
        self::assertSame(Levels::DEFAULT, $this->app->balances->levelOf($scale->id, $vasya->id));
    }
}
