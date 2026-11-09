<?php

declare(strict_types=1);

namespace P1Bot\View;

/**
	Escaping for Telegram parse_mode=HTML.
*/
class Html {

	/** @var array<string, true> */
	public const TELEGRAM_TAGS = [
		'b' => true,
		'i' => true,
		'u' => true,
		's' => true,
		'a' => true,
		'code' => true,
		'pre' => true,
		'tg-spoiler' => true,
		'blockquote' => true,
	];

	public static function e(string $text): string {
		return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	public static function b(string $text): string {
		return '<b>' . self::e($text) . '</b>';
	}

	/**
		Tag names in $html that Telegram parse_mode=HTML will reject.
		Closing tags count (/b → b). Empty list = safe.
		@return list<string>
	*/
	public static function illegalTelegramTags(string $html): array {
		preg_match_all('/<\/?([a-zA-Zа-яА-ЯёЁ][a-zA-Zа-яА-ЯёЁ0-9_-]*)\b[^>]*>/u', $html, $m);
		$bad = [];
		foreach ($m[1] as $raw) {
			$name = mb_strtolower($raw);
			if (!isset(self::TELEGRAM_TAGS[$name])) {
				$bad[] = $raw;
			}
		}
		return $bad;
	}
}
