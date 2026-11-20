<?php

declare(strict_types=1);

namespace P1Bot\View;

/**
	Mini App URLs derived from WEBHOOK_URL (same host and directory).
	https://host/webhook.php → https://host/webapp/picker.html
	https://host/p1/webhook.php → https://host/p1/webapp/create.html
	Never hardcodes a domain.
*/
class WebAppUrl {
	public const PICKER = 'picker.html';
	public const CREATE_FORM = 'create.html';
	public const TEAM_FORM = 'team.html';

	/**
		https URL of a page under public/webapp/, or null when WEBHOOK_URL is not https.
	*/
	public static function page(string $webhook_url, string $file): ?string {
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
		return strtolower($parts['scheme']) . '://' . $parts['host'] . $port . $dir . '/webapp/' . $file;
	}

	public static function pickerPage(string $webhook_url): ?string {
		return self::page($webhook_url, self::PICKER);
	}

	public static function picker(string $webhook_url, string $token): ?string {
		return self::withToken(self::pickerPage($webhook_url), $token);
	}

	public static function createForm(string $webhook_url, string $token): ?string {
		return self::withToken(self::page($webhook_url, self::CREATE_FORM), $token);
	}

	/**
		The same form in edit mode: create.html?ctx=…&mode=edit (the page picks the API ops by mode).
	*/
	public static function editForm(string $webhook_url, string $token): ?string {
		$url = self::createForm($webhook_url, $token);
		return $url === null ? null : $url . '&mode=edit';
	}

	public static function teamForm(string $webhook_url, string $token): ?string {
		return self::withToken(self::page($webhook_url, self::TEAM_FORM), $token);
	}

	protected static function withToken(?string $page, string $token): ?string {
		if ($page === null || $token === '') {
			return null;
		}
		return $page . '?ctx=' . rawurlencode($token);
	}
}
