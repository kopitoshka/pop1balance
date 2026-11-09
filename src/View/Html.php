<?php

declare(strict_types=1);

namespace P1Bot\View;

/**
	Escaping for Telegram parse_mode=HTML.
*/
class Html {

	public static function e(string $text): string {
		return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	public static function b(string $text): string {
		return '<b>' . self::e($text) . '</b>';
	}
}
