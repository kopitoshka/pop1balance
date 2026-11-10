<?php

declare(strict_types=1);

namespace P1Bot\View;

/**
	t.me links into the private chat with the bot: start=t_<tournament id> or start=g_<group chat id>.
*/
class DeepLink {

	public static function url(string $bot_username, string $payload = ''): string {
		return 'https://t.me/' . $bot_username . ($payload !== '' ? '?start=' . $payload : '');
	}

	public static function tournament(string $bot_username, int $tournament_id): string {
		return self::url($bot_username, 't_' . $tournament_id);
	}

	public static function group(string $bot_username, int $chat_id): string {
		return self::url($bot_username, 'g_' . $chat_id);
	}

	/**
		@return array{tournament: ?int, group: ?int, reg_fill: ?int}
	*/
	public static function parse(string $payload): array {
		$payload = trim($payload);
		if (preg_match('/^t_(\d+)$/', $payload, $m)) {
			return ['tournament' => (int) $m[1], 'group' => null, 'reg_fill' => null];
		}
		if (preg_match('/^g_(-?\d+)$/', $payload, $m)) {
			return ['tournament' => null, 'group' => (int) $m[1], 'reg_fill' => null];
		}
		if (preg_match('/^rf_(\d+)$/', $payload, $m)) {
			return ['tournament' => null, 'group' => null, 'reg_fill' => (int) $m[1]];
		}
		return ['tournament' => null, 'group' => null, 'reg_fill' => null];
	}

	/**
		Inline keyboard with one URL button.

		@return array<string, mixed>
	*/
	public static function button(string $text, string $url): array {
		return ['reply_markup' => ['inline_keyboard' => [[['text' => $text, 'url' => $url]]]]];
	}
}
