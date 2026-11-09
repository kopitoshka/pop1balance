<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use P1Bot\Domain\Model\Player;
use PHPUnit\Framework\TestCase;

class PlayerLabelTest extends TestCase {

	public function testIgnWithTgIdIsLinked(): void {
		$p = new Player(1, 42, null, 'Display', 'Mata', 'mata');
		self::assertSame(
			'<a href="tg://user?id=42">Mata</a>',
			$p->lineupLabel(),
		);
	}

	public function testUsernameWithoutIgnIsLinkedViaTgId(): void {
		$p = new Player(1, 99, 'kidding2u', 'Kid');
		self::assertSame(
			'<a href="tg://user?id=99">@kidding2u</a>',
			$p->lineupLabel(),
		);
	}

	public function testUsernameWithoutTgIdUsesTMeLink(): void {
		$p = new Player(1, null, 'solo_user', 'Solo');
		self::assertSame(
			'<a href="https://t.me/solo_user">@solo_user</a>',
			$p->lineupLabel(),
		);
	}

	public function testDisplayNameWithTgIdIsLinked(): void {
		$p = new Player(1, 7, null, 'Plain Name');
		self::assertSame(
			'<a href="tg://user?id=7">Plain Name</a>',
			$p->lineupLabel(),
		);
	}
}
