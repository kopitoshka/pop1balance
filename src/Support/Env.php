<?php

declare(strict_types=1);

namespace P1Bot\Support;

/**
	Minimal .env loader: KEY=value lines, '#' comments, optional quotes.
	Real environment variables always win over the file.
*/
class Env {
	/**
		@var array<string, string>
	*/
	protected static array $values = [];

	public static function load(string $path): void {
		if (!is_file($path)) {
			return;
		}
		$lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
		foreach ($lines as $line) {
			$line = trim($line);
			if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
				continue;
			}
			[$key, $value] = array_map('trim', explode('=', $line, 2));
			if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
				$value = substr($value, 1, -1);
			}
			self::$values[$key] = $value;
		}
	}

	public static function get(string $key, string $default = ''): string {
		$env = getenv($key);
		if ($env !== false) {
			return $env;
		}
		return self::$values[$key] ?? $default;
	}

	public static function int(string $key, int $default): int {
		$value = self::get($key, '');
		return is_numeric($value) ? (int) $value : $default;
	}

	public static function bool(string $key, bool $default): bool {
		$value = strtolower(self::get($key, ''));
		if ($value === '') {
			return $default;
		}
		return in_array($value, ['1', 'true', 'yes', 'on'], true);
	}
}
