<?php

declare(strict_types=1);

namespace P1Bot\View;

use P1Bot\Domain\Lineup;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\MoscowTime;

/**
	User-facing texts. Only the tournament headline for now; announcements come later.
*/
class Messages {
	/**
		One reply in a group to anything but the info commands: everything happens in private.
	*/
	public const GROUP_REDIRECT = 'Давай в личке 👉';

	/**
		Text of the URL button under GROUP_REDIRECT.
	*/
	public const GROUP_REDIRECT_BUTTON = '💬 Написать в личку';

	/**
		Answer to buttons of old group cards (solo/howto/pub/fix): everything moved to private.
	*/
	public const LEGACY_BUTTON = 'Теперь это в личке — открываю чат со мной 👉';

	/**
		Reply to an edited message addressed to the bot: edits are not processed.
	*/
	public const EDITED_MESSAGE = 'Исправленные сообщения я не вижу 🙈 Напиши, пожалуйста, заново новым сообщением';

	/**
		"Субботний кубок — 14.11.2026 19:00 МСК, команды по 3, уровень: средний (10 баллов на троих), занято 18/24, резерв 2"
	*/
	public static function tournamentLine(Tournament $t, Lineup $lineup): string {
		return sprintf(
			'%s — %s, команды по %d, уровень: %s, %s',
			$t->title,
			MoscowTime::format($t->starts_at),
			$t->team_size,
			$t->levelLabel(),
			$lineup->summary(),
		);
	}
}
