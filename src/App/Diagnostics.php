<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Support\Database;
use P1Bot\Support\Logger;
use P1Bot\Support\Migrations;
use P1Bot\Support\Redact;
use P1Bot\Telegram\BotApi;
use P1Bot\Telegram\Update;
use Throwable;

/**
	Health report for bin/diag.php. Prints no secrets: the webhook host is masked,
	keys and secrets are reported only as set / not set.
*/
class Diagnostics {

	/**
		@param callable(): Database $connect
	*/
	public function __construct(
		protected array $config,
		protected ?BotApi $api,
		protected $connect,
		protected string $migrations_dir,
	) {
	}

	/**
		@return list<string>
	*/
	public function lines(): array {
		return array_merge(
			['== Telegram webhook'],
			$this->webhook(),
			['', '== Bot username'],
			$this->username(),
			['', '== Database'],
			$this->database(),
			['', '== Logs'],
			$this->logs(),
			['', '== OpenAI'],
			['OPENAI_API_KEY set: ' . ((string) ($this->config['openai']['api_key'] ?? '') !== '' ? 'yes' : 'no (bot answers mentions with a fallback text)')],
		);
	}

	public static function maskUrl(string $url): string {
		if ($url === '') {
			return '(not set)';
		}
		$parts = parse_url($url);
		if (!is_array($parts) || !isset($parts['host'])) {
			return '***';
		}
		return ($parts['scheme'] ?? 'https') . '://***' . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '');
	}

	/**
		@return list<string>
	*/
	protected function webhook(): array {
		$out = ['TELEGRAM_WEBHOOK_SECRET set: ' . ((string) ($this->config['telegram']['webhook_secret'] ?? '') !== '' ? 'yes' : 'NO — every update will be rejected with 403')];
		if ($this->api === null) {
			return array_merge($out, ['TELEGRAM_BOT_TOKEN is empty — cannot ask Telegram']);
		}
		try {
			$info = $this->api->getWebhookInfo();
		} catch (Throwable $e) {
			return array_merge($out, ['getWebhookInfo failed: ' . $this->safe($e->getMessage())]);
		}
		$url = (string) ($info['url'] ?? '');
		$configured = (string) ($this->config['telegram']['webhook_url'] ?? '');
		$out[] = 'url: ' . self::maskUrl($url);
		if ($url === '') {
			$out[] = '  !! webhook is not set — run php bin/set_webhook.php';
		} elseif ($configured !== '' && $url !== $configured) {
			$out[] = '  !! differs from WEBHOOK_URL in .env (' . self::maskUrl($configured) . ') — run php bin/set_webhook.php';
		} else {
			$out[] = '  matches WEBHOOK_URL: ' . ($configured === '' ? 'WEBHOOK_URL not set' : 'yes');
		}
		$out[] = 'pending_update_count: ' . (int) ($info['pending_update_count'] ?? 0);
		if (isset($info['last_error_date'])) {
			$out[] = sprintf(
				'last_error: %s — %s',
				$this->moscow((int) $info['last_error_date']),
				$this->safe((string) ($info['last_error_message'] ?? '')),
			);
		} else {
			$out[] = 'last_error: none';
		}
		if (isset($info['last_synchronization_error_date'])) {
			$out[] = 'last_synchronization_error: ' . $this->moscow((int) $info['last_synchronization_error_date']);
		}
		$allowed = isset($info['allowed_updates']) && is_array($info['allowed_updates']) ? array_map('strval', $info['allowed_updates']) : [];
		$out[] = 'allowed_updates: ' . ($allowed === [] ? '(default: all except chat_member etc.)' : implode(', ', $allowed));
		if ($allowed !== []) {
			$missing = array_diff(Update::ALLOWED_UPDATES, $allowed);
			if ($missing !== []) {
				$out[] = '  !! missing ' . implode(', ', $missing) . ' — run php bin/set_webhook.php';
			}
		}
		return $out;
	}

	/**
		@return list<string>
	*/
	protected function username(): array {
		$configured = ltrim((string) ($this->config['telegram']['username'] ?? ''), '@');
		$out = ['TELEGRAM_BOT_USERNAME: ' . ($configured !== '' ? '@' . $configured : '(empty!)')];
		if ($this->api === null) {
			return $out;
		}
		try {
			$me = $this->api->getMe();
		} catch (Throwable $e) {
			return array_merge($out, ['getMe failed: ' . $this->safe($e->getMessage())]);
		}
		$real = (string) ($me['username'] ?? '');
		$out[] = 'getMe username: @' . $real;
		$out[] = strcasecmp($real, $configured) === 0
			? 'match: yes'
			: 'match: NO — @mentions are compared with TELEGRAM_BOT_USERNAME, fix it in .env to ' . $real;
		if (array_key_exists('can_read_all_group_messages', $me)) {
			$out[] = 'privacy mode: ' . ($me['can_read_all_group_messages'] ? 'off (bot sees all messages)' : 'on (bot sees commands, mentions and replies only)');
		}
		return $out;
	}

	/**
		@return list<string>
	*/
	protected function database(): array {
		try {
			$db = ($this->connect)();
			$db->pdo()->query('SELECT 1');
		} catch (Throwable $e) {
			return ['connection: FAILED — ' . $this->safe($e->getMessage())];
		}
		$migrations = new Migrations($db->pdo(), $this->migrations_dir);
		$pending = $migrations->pending();
		$out = ['connection: ok'];
		if (!$migrations->hasTrackingTable()) {
			$out[] = 'schema_migrations table: missing (php bin/migrate.php creates it)';
		}
		$out[] = 'pending migrations: ' . ($pending === [] ? 'none' : implode(', ', $pending) . ' — run php bin/migrate.php');
		return $out;
	}

	/**
		@return list<string>
	*/
	protected function logs(): array {
		$logger = Logger::fromConfig($this->config);
		$dir = (string) ($this->config['paths']['logs'] ?? '');
		return [
			'LOG_LEVEL: ' . $logger->level(),
			'logs dir writable (by ' . $this->whoami() . '): ' . ($logger->isWritable() ? 'yes' : 'NO — ' . $dir . ' (logs go to the PHP error_log instead)'),
			'  note: the webhook runs as the php-fpm user, check that user can write there too',
		];
	}

	protected function whoami(): string {
		if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
			$user = posix_getpwuid(posix_geteuid());
			if (is_array($user) && isset($user['name'])) {
				return (string) $user['name'];
			}
		}
		return (string) get_current_user();
	}

	protected function moscow(int $timestamp): string {
		return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d H:i:s') . ' MSK';
	}

	protected function safe(string $text): string {
		return Redact::text($text, [
			(string) ($this->config['telegram']['token'] ?? ''),
			(string) ($this->config['telegram']['webhook_secret'] ?? ''),
			(string) ($this->config['openai']['api_key'] ?? ''),
			(string) ($this->config['db']['password'] ?? ''),
		], 300);
	}
}
