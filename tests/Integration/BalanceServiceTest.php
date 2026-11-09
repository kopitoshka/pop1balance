<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Levels;
use P1Bot\Tests\Support\IntegrationTestCase;

class BalanceServiceTest extends IntegrationTestCase {

	public function testDefaultScaleBelongsToOwnerAndIsCreatedOnce(): void {
		$default = $this->app->balances->findDefault();
		self::assertNotNull($default);
		self::assertSame($this->owner->id, $default->owner_id);
		self::assertTrue($default->is_default);
		self::assertSame($default->id, $this->app->balances->ensureDefault($this->owner->id)->id);
	}

	public function testUnknownPlayerIsAverage(): void {
		$vasya = $this->player('vasya');
		$default = $this->app->balances->findDefault();
		self::assertSame(Levels::DEFAULT, $this->app->balances->levelOf($default->id, $vasya->id));
		self::assertSame(Levels::DEFAULT, $this->app->balances->levelOf(null, $vasya->id));
	}

	public function testOwnerRatesInDefaultScale(): void {
		$vasya = $this->player('vasya');
		$scale = $this->app->balances->setLevel(self::CHAT_ID, $this->owner->id, $vasya->id, 5);
		self::assertTrue($scale->is_default);
		self::assertSame(5, $this->app->balances->levelOf($scale->id, $vasya->id));
	}

	public function testInheritedScaleOverridesLevels(): void {
		$vasya = $this->player('vasya');
		$petya = $this->player('petya');
		$this->rate($vasya, 5);
		$this->rate($petya, 2);
		$default = $this->app->balances->findDefault();

		$child = $this->app->balances->create(self::CHAT_ID, $this->owner->id, 'Своя шкала', $default->id);
		$this->app->balances->setLevel(self::CHAT_ID, $this->owner->id, $vasya->id, 4, $child->id);

		self::assertFalse($child->is_default);
		self::assertSame($default->id, $child->parent_id);
		self::assertSame(4, $this->app->balances->levelOf($child->id, $vasya->id), 'own rating wins');
		self::assertSame(2, $this->app->balances->levelOf($child->id, $petya->id), 'inherited from parent');
		self::assertSame(5, $this->app->balances->levelOf($default->id, $vasya->id), 'parent untouched');

		$ratings = $this->app->balances->effectiveRatings($child->id);
		self::assertSame(['level' => 4, 'balance_id' => $child->id], $ratings[$vasya->id]);
		self::assertSame(['level' => 2, 'balance_id' => $default->id], $ratings[$petya->id]);

		$this->app->balances->resetLevel(self::CHAT_ID, $this->owner->id, $vasya->id, $child->id);
		self::assertSame(5, $this->app->balances->levelOf($child->id, $vasya->id));
	}

	/**
		Temporary rule: only the owner changes levels and scales, chat admins included.
	*/
	public function testChatAdminCannotRateOrCreateScales(): void {
		$vasya = $this->player('vasya');
		try {
			$this->app->balances->setLevel(self::CHAT_ID, $this->admin->id, $vasya->id, 4);
			self::fail('admin must not rate');
		} catch (PermissionDenied $e) {
			self::assertSame('Уровни игроков меняет только главный админ.', $e->getMessage());
		}
		$this->expectException(PermissionDenied::class);
		$this->app->balances->create(self::CHAT_ID, $this->admin->id, 'Своя шкала');
	}

	public function testAdminCannotEditSomeoneElsesScale(): void {
		$vasya = $this->player('vasya');
		$default = $this->app->balances->findDefault();
		$this->expectException(PermissionDenied::class);
		$this->app->balances->setLevel(self::CHAT_ID, $this->admin->id, $vasya->id, 4, $default->id);
	}

	public function testRegularPlayerCannotRate(): void {
		$vasya = $this->player('vasya');
		$this->expectException(PermissionDenied::class);
		$this->app->balances->setLevel(self::CHAT_ID, $vasya->id, $vasya->id, 6);
	}

	public function testLevelRange(): void {
		$vasya = $this->player('vasya');
		$this->expectException(ValidationError::class);
		$this->rate($vasya, 7);
	}

	public function testCreateScaleWithoutParent(): void {
		$vasya = $this->player('vasya');
		$this->rate($vasya, 6);
		$scale = $this->app->balances->create(self::CHAT_ID, $this->owner->id, 'Своя шкала');
		self::assertNull($scale->parent_id);
		self::assertSame(Levels::DEFAULT, $this->app->balances->levelOf($scale->id, $vasya->id));
	}
}
