<?php

declare(strict_types=1);

namespace P1Bot\View;

use P1Bot\Domain\Lineup;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\MoscowTime;
use P1Bot\Support\RandomSource;

/**
	User-facing texts for redirects, group join/leave and tournament lines.
*/
class Messages {
	/**
		Legacy single redirect (kept for callers that still expect one fixed string).
	*/
	public const GROUP_REDIRECT = 'Давай в личке 👉';

	public const GROUP_REDIRECT_BUTTON = '💬 Написать в личку';

	public const LEGACY_BUTTON = 'Теперь это в личке — открываю чат со мной 👉';

	public const EDITED_MESSAGE = 'Исправленные сообщения я не вижу 🙈 Напиши, пожалуйста, заново новым сообщением';

	public const GROUP_HELP_HINT = 'А ещё больше умею в личке — записать, собрать команду, создать турнир 😉';

	/** Unrecognized group mention: no free chat, point to /help. */
	public const GROUP_FALLBACK = 'Не поняла 🙅‍♀️ Что я умею — /help';

	public const REGISTRATION_CLOSED = 'Регистрация уже закрыта, составы не меняются ⏰';

	public const ALREADY_SOLO = 'Ты уже записан на этот турнир 🙂';

	public const NOT_REGISTERED = 'Тебя и так нет в этом турнире 🙂';

	public const CAPTAIN_LEAVE = 'Ты капитан, тут сложнее — давай в личке 👉';

	/**
		Management / create actions redirected from a group.
		@var list<string>
	*/
	public const MANAGE_REDIRECTS = [
		'Ой, тут при всех не могу 🙈 Пойдём в личку 👉',
		'Такие дела я решаю только тет-а-тет 😏 Жду в личке 👉',
		'Это секретная операция 🕵️‍♀️ Встречаемся в личке 👉',
	];

	/**
		Several open tournaments, no number named.
		@var list<string>
	*/
	public const AMBIGUOUS_REDIRECTS = [
		'Ой, турниров сейчас несколько 🤔 Пойдём в личке, расскажешь подробнее 👉',
		'Так, а на какой из них? 😅 Давай в личке разберёмся 👉',
		'Глаза разбегаются от турниров 👀 Пошли в личку, там выберем 👉',
	];

	public static function manageRedirect(RandomSource $random): string {
		return (string) $random->pick(self::MANAGE_REDIRECTS);
	}

	public static function ambiguousRedirect(RandomSource $random): string {
		return (string) $random->pick(self::AMBIGUOUS_REDIRECTS);
	}

	public static function joinedMain(string $title, int $occupied, int $max): string {
		return sprintf('Записала тебя на «%s» ✅ Ты в основе, %d/%d.', $title, $occupied, $max);
	}

	public static function joinedReserve(string $title, int $reserve_no): string {
		return sprintf(
			'Записала тебя на «%s» ✅ Основа уже набрана, ты в запасе, №%d.',
			$title,
			$reserve_no,
		);
	}

	public static function leftTournament(string $title): string {
		return sprintf('Вычеркнула тебя из «%s» 👋 Будем скучать!', $title);
	}

	public static function leftTeam(string $team, string $title): string {
		return sprintf('Убрала тебя из команды «%s» на «%s» 👋', $team, $title);
	}

	public static function memberLeftTeam(string $who, string $team, int $tournament_no): string {
		return sprintf('%s отписался из команды «%s» (турнир #%d)', $who, $team, $tournament_no);
	}

	public static function captainDisbanded(string $team, string $title): string {
		return sprintf(
			'Капитан распустил команду «%s», ты больше не в турнире «%s». Записаться соло или в другую команду — жми 👇',
			$team,
			$title,
		);
	}

	public static function organizerDisbanded(string $team, string $title): string {
		return sprintf(
			'Организатор распустил команду «%s», ты больше не в турнире «%s». Записаться соло или в другую команду — жми 👇',
			$team,
			$title,
		);
	}

	/**
		"Субботний кубок — 14.11.2026 19:00 МСК, команды по 3, уровень: средний (10 баллов на троих), занято 18/24, запас 2"
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
