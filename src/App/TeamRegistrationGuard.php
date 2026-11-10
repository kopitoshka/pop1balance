<?php

declare(strict_types=1);

namespace P1Bot\App;

/**
	Detects when the model asked for @usernames instead of calling register_team,
	and pulls a team name plus raw player tokens out of the user's message.
*/
class TeamRegistrationGuard {

	/** @var list<string> */
	protected const STOP = [
		'команда', 'команду', 'команды', 'команде', 'командой',
		'турнир', 'турнира', 'турнире', 'турниры',
		'запиши', 'запишите', 'записать', 'записывай', 'зарегистрируй',
		'создай', 'создать', 'пожалуйста', 'меня', 'нам', 'нас', 'тебя',
		'или', 'это', 'ник', 'ники', 'ником', 'ников', 'ником',
		'telegram', 'телеграм', 'телеграма', 'формате', 'формат',
		'для', 'под', 'название', 'названием', 'называется',
		'игровой', 'игровые', 'игровым', 'username', 'user',
		'их', 'ему', 'ей', 'его', 'её', 'мой', 'моя', 'наши',
		'капитан', 'капитана', 'капитаном', 'состав', 'составе',
		'нужны', 'нужен', 'нужна', 'привет', 'спасибо',
	];

	public static function isUsernameAsk(string $text): bool {
		return preg_match(
			'/@username|в\s+формате\s*@|telegram[-\s]?ник|телеграм[а-я]*[-\s]?ник|пришли(?:те)?\s+(?:пожалуйста\s+)?(?:их\s+)?(?:телеграм[а-я]*[-\s]?|telegram[-\s]?)?ник/iu',
			$text,
		) === 1;
	}

	public static function isTeamRegistrationIntent(string $text): bool {
		return preg_match(
			'/команд|(запис(ать|ывай|и)|зарегистрир)|созда[йть].{0,40}команд/u',
			mb_strtolower($text),
		) === 1;
	}

	/**
		@return array{team_name: string, players: list<string>}|null
	*/
	public static function parse(string $text): ?array {
		$name = self::extractTeamName($text);
		if ($name === null) {
			return null;
		}
		$players = self::extractPlayers($text, $name);
		if ($players === []) {
			return null;
		}
		return ['team_name' => $name, 'players' => $players];
	}

	protected static function extractTeamName(string $text): ?string {
		if (preg_match('/команд(?:а|у|ы|е|ой)?\s+[«""]([^«»""]+)[»""]/iu', $text, $m)) {
			$name = trim($m[1]);
			return $name !== '' ? $name : null;
		}
		if (preg_match('/команд(?:а|у|ы|е|ой)?\s+(?:называется\s+|под\s+названием\s+)?([^\s,.:;!?]+)/iu', $text, $m)) {
			$name = trim($m[1], "«»\"'");
			if ($name !== '' && !self::isStop($name)) {
				return $name;
			}
		}
		return null;
	}

	/**
		@param list<string> $out unused
		@return list<string>
	*/
	protected static function extractPlayers(string $text, string $team_name): array {
		$nicks = [];
		$names = [];
		$team_fold = self::fold($team_name);
		if (preg_match_all('/[«""]([^«»""]+)[»""]/u', $text, $m)) {
			foreach ($m[1] as $raw) {
				$raw = trim($raw);
				if ($raw === '' || self::fold($raw) === $team_fold || self::isStop($raw)) {
					continue;
				}
				if (self::isNameToken($raw)) {
					$names[] = $raw;
				} else {
					$nicks[] = $raw;
				}
			}
		}
		if (preg_match_all('/\(([^)]{2,40})\)/u', $text, $m)) {
			foreach ($m[1] as $raw) {
				$raw = trim($raw);
				if ($raw === '' || self::fold($raw) === $team_fold || self::isStop($raw)) {
					continue;
				}
				$nicks[] = $raw;
			}
		}
		if (preg_match_all('/@([A-Za-z][A-Za-z0-9_]{2,31})/', $text, $m)) {
			foreach ($m[1] as $username) {
				$nicks[] = '@' . $username;
			}
		}
		// Unquoted multi-word Latin IGNs: tired angel
		if (preg_match_all('/(?<![@A-Za-z0-9_])([A-Za-z][A-Za-z0-9]*(?:\s+[A-Za-z][A-Za-z0-9]*)+)/u', $text, $m)) {
			foreach ($m[1] as $raw) {
				$raw = trim($raw);
				if (!self::isStop($raw) && self::fold($raw) !== $team_fold) {
					$nicks[] = $raw;
				}
			}
		}
		if (preg_match_all('/(?<![@A-Za-z0-9_])([A-Za-z][A-Za-z0-9_]{2,31})(?![A-Za-z0-9_])/u', $text, $m)) {
			foreach ($m[1] as $raw) {
				if (!self::isStop($raw)) {
					$nicks[] = $raw;
				}
			}
		}
		if (preg_match_all('/\b([А-ЯЁ][а-яё]{2,})\b/u', $text, $m)) {
			foreach ($m[1] as $raw) {
				if (!self::isStop($raw) && self::fold($raw) !== $team_fold) {
					$names[] = $raw;
				}
			}
		}
		$preferred = $nicks !== [] ? $nicks : $names;
		return self::uniqueTokens($preferred);
	}

	protected static function isNameToken(string $raw): bool {
		return preg_match('/^[А-ЯЁа-яё\-\s]{3,}$/u', $raw) === 1;
	}

	/**
		@param list<string> $tokens
		@return list<string>
	*/
	protected static function uniqueTokens(array $tokens): array {
		$seen = [];
		$out = [];
		foreach ($tokens as $token) {
			$token = trim($token);
			$fold = self::fold($token);
			if ($token === '' || $fold === '' || isset($seen[$fold]) || self::isStop($token)) {
				continue;
			}
			$seen[$fold] = true;
			$out[] = $token;
		}
		return $out;
	}

	protected static function isStop(string $word): bool {
		return in_array(mb_strtolower(trim($word, "«»\"'@.,!?")), self::STOP, true);
	}

	protected static function fold(string $value): string {
		$value = mb_strtolower(ltrim(trim($value), '@'));
		return (string) preg_replace('/[\s_\-\.]+/u', '', $value);
	}
}
