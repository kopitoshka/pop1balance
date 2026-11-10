<?php

declare(strict_types=1);

namespace P1Bot\View;

/**
	Mini App URL derived from WEBHOOK_URL (same host and directory).
	https://host/webhook.php → https://host/webapp/picker.html
	https://host/p1/webhook.php → https://host/p1/webapp/picker.html
	Never hardcodes a domain.
*/
class WebAppUrl {

	public static function pickerPage(string $webhook_url): ?string {
		$webhook_url = trim($webhook_url);
		if ($webhook_url === '' || preg_match('~^https://~i', $webhook_url) !== 1) {
			return null;
		}
		$parts = parse_url($webhook_url);
		if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
			return null;
		}
		$port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
		$dir = isset($parts['path']) ? str_replace('\\', '/', dirname($parts['path'])) : '';
		if ($dir === '/' || $dir === '.' || $dir === '') {
			$dir = '';
		} else {
			$dir = rtrim($dir, '/');
		}
		return strtolower($parts['scheme']) . '://' . $parts['host'] . $port . $dir . '/webapp/picker.html';
	}

	public static function picker(string $webhook_url, string $token): ?string {
		$page = self::pickerPage($webhook_url);
		if ($page === null || $token === '') {
			return null;
		}
		return $page . '?ctx=' . rawurlencode($token);
	}
}
