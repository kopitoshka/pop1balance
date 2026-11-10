<?php

declare(strict_types=1);

namespace P1Bot\Telegram;

use P1Bot\Domain\Exception\ValidationError;

/**
	Validates Telegram Mini App initData (HMAC-SHA256, key WebAppData).
	https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
*/
class WebAppInitData {

	public const MAX_AGE_SECONDS = 3600;

	/**
		@param array{user_id: int, username: ?string, first_name: string, auth_date: int, query: array<string, string>} $parsed
	*/
	public static function parse(string $init_data, string $bot_token, int $now, int $max_age = self::MAX_AGE_SECONDS): array {
		$init_data = trim($init_data);
		if ($init_data === '' || $bot_token === '') {
			throw new ValidationError('Нет данных Mini App.');
		}
		$pairs = [];
		parse_str($init_data, $pairs);
		if (!is_array($pairs) || !isset($pairs['hash']) || !is_string($pairs['hash']) || $pairs['hash'] === '') {
			throw new ValidationError('Нет подписи Mini App.');
		}
		$hash = $pairs['hash'];
		unset($pairs['hash']);
		$fields = [];
		foreach ($pairs as $key => $value) {
			if (!is_string($key) || !is_string($value)) {
				continue;
			}
			$fields[$key] = $value;
		}
		ksort($fields);
		$lines = [];
		foreach ($fields as $key => $value) {
			$lines[] = $key . '=' . $value;
		}
		$data_check = implode("\n", $lines);
		$secret = hash_hmac('sha256', $bot_token, 'WebAppData', true);
		$computed = bin2hex(hash_hmac('sha256', $data_check, $secret, true));
		if (!hash_equals($computed, strtolower($hash))) {
			throw new ValidationError('Подпись Mini App неверна.');
		}
		$auth_date = isset($fields['auth_date']) && ctype_digit($fields['auth_date'])
			? (int) $fields['auth_date']
			: 0;
		if ($auth_date <= 0 || ($now - $auth_date) > $max_age || $auth_date > ($now + 60)) {
			throw new ValidationError('Данные Mini App устарели. Закрой окно и открой снова.');
		}
		$user = [];
		if (isset($fields['user']) && $fields['user'] !== '') {
			$decoded = json_decode($fields['user'], true);
			if (is_array($decoded)) {
				$user = $decoded;
			}
		}
		$user_id = isset($user['id']) ? (int) $user['id'] : 0;
		if ($user_id <= 0) {
			throw new ValidationError('В Mini App нет пользователя.');
		}
		$username = isset($user['username']) && is_string($user['username']) && $user['username'] !== ''
			? (string) $user['username']
			: null;
		$first_name = isset($user['first_name']) && is_string($user['first_name'])
			? (string) $user['first_name']
			: '';
		return [
			'user_id' => $user_id,
			'username' => $username,
			'first_name' => $first_name,
			'auth_date' => $auth_date,
			'query' => $fields,
		];
	}

	/**
		Build a signed initData string (tests).
	*/
	public static function sign(array $fields, string $bot_token): string {
		$parts = $fields;
		unset($parts['hash']);
		ksort($parts);
		$lines = [];
		foreach ($parts as $key => $value) {
			$lines[] = $key . '=' . $value;
		}
		$secret = hash_hmac('sha256', $bot_token, 'WebAppData', true);
		$hash = bin2hex(hash_hmac('sha256', implode("\n", $lines), $secret, true));
		$pairs = [];
		foreach ($fields as $key => $value) {
			$pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
		}
		$pairs[] = 'hash=' . $hash;
		return implode('&', $pairs);
	}
}
