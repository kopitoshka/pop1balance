<?php

declare(strict_types=1);

namespace P1Bot\Support;

use DateTimeZone;
use P1Bot\Domain\Model\Player;

/**
	Audit log of the /su operations only: storage/logs/su.log, one line per operation.

		[2026-11-22 03:17:00 MSK] on by @andrey (id 1, tg 1000)
		[2026-11-22 03:18:30 MSK] grant by @andrey (id 1, tg 1000) target @vasya (id 7, tg 2001)

	Operations: on, off, grant, revoke. Nothing else is written here. A null directory disables
	the log (tests). A directory that cannot be written is skipped silently: the operation itself
	must not fail because of the log.
*/
class SuLog {
	public const FILE = 'su.log';

	public function __construct(protected ?string $dir, protected Clock $clock) {
	}

	public function enabled(): bool {
		return $this->dir !== null;
	}

	/**
		@param string $op on|off|grant|revoke
	*/
	public function write(string $op, Player $actor, ?Player $target = null): void {
		if ($this->dir === null) {
			return;
		}
		$time = $this->clock->now()->setTimezone(new DateTimeZone('Europe/Moscow'));
		$line = sprintf('[%s MSK] %s by %s', $time->format('Y-m-d H:i:s'), $op, self::who($actor));
		if ($target !== null) {
			$line .= ' target ' . self::who($target);
		}
		if (!is_dir($this->dir)) {
			@mkdir($this->dir, 0775, true);
		}
		@file_put_contents($this->dir . '/' . self::FILE, $line . "\n", FILE_APPEND | LOCK_EX);
	}

	protected static function who(Player $player): string {
		$name = $player->username !== null && $player->username !== '' ? '@' . $player->username : $player->plainLabel();
		return sprintf('%s (id %d, tg %s)', str_replace(["\n", "\r"], ' ', $name), $player->id, $player->tg_user_id ?? '-');
	}
}
