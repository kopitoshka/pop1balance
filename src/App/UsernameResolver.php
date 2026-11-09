<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Support\Clock;
use P1Bot\Support\Logger;

/**
	Resolves unknown @usernames via bin/resolve_username.py (Telethon, bot token).
	Negative results are cached ~10 minutes; flood_wait blocks until retry_after.
*/
class UsernameResolver implements UsernameLookup {

	protected const NEGATIVE_TTL = 600;

	/**
		@param array{
			api_id?: int|string,
			api_hash?: string,
			token?: string,
			python?: string,
			script?: string,
			session_dir?: string,
			timeout?: int,
			negative_ttl?: int
		} $config
		@param callable(string): array{0: int, 1: string, 2: string}|null $runner
			test double: username → [exit_code, stdout, stderr]
	*/
	public function __construct(
		protected array $config,
		protected Clock $clock,
		protected ?Logger $logger = null,
		protected $runner = null,
	) {
	}

	public function isAvailable(): bool {
		$api_id = (int) ($this->config['api_id'] ?? 0);
		$api_hash = trim((string) ($this->config['api_hash'] ?? ''));
		$token = trim((string) ($this->config['token'] ?? ''));
		if ($api_id <= 0 || $api_hash === '' || $token === '') {
			return false;
		}
		$script = (string) ($this->config['script'] ?? '');
		if ($script === '' || !is_file($script)) {
			return false;
		}
		return true;
	}

	public function resolve(string $username): ?array {
		$username = ltrim(trim($username), '@');
		if ($username === '' || !preg_match('/^[A-Za-z][A-Za-z0-9_]{2,31}$/', $username)) {
			return null;
		}
		if (!$this->isAvailable()) {
			return null;
		}
		if ($this->floodBlocked()) {
			$this->logger?->info('username resolve skipped: flood_wait cooldown', ['username' => $username]);
			return null;
		}
		$cached = $this->readNegative($username);
		if ($cached !== null) {
			$this->logger?->debug('username resolve negative cache hit', ['username' => $username]);
			return null;
		}

		[$code, $stdout, $stderr] = $this->invoke($username);
		$line = trim($stdout);
		if ($line === '') {
			$this->logger?->error('username resolve empty stdout', [
				'username' => $username,
				'code' => $code,
				'stderr' => mb_substr($stderr, 0, 200),
			]);
			return null;
		}
		// One username → first JSON line.
		$first = strtok($line, "\n") ?: $line;
		$data = json_decode($first, true);
		if (!is_array($data)) {
			$this->logger?->error('username resolve bad json', ['username' => $username, 'out' => mb_substr($first, 0, 200)]);
			return null;
		}
		if (!empty($data['ok'])) {
			$id = (int) ($data['id'] ?? 0);
			if ($id <= 0) {
				return null;
			}
			$actual = isset($data['username_actual']) && $data['username_actual'] !== ''
				? (string) $data['username_actual']
				: $username;
			return [
				'id' => $id,
				'first_name' => (string) ($data['first_name'] ?? ''),
				'last_name' => (string) ($data['last_name'] ?? ''),
				'username' => $actual,
			];
		}

		$error = (string) ($data['error'] ?? 'unknown');
		if ($error === 'flood_wait') {
			$retry = max(1, (int) ($data['retry_after'] ?? 60));
			$this->rememberFlood($retry);
			$this->logger?->info('username resolve flood_wait', ['retry_after' => $retry]);
			return null;
		}
		if (in_array($error, ['not_found', 'not_user'], true)) {
			$this->writeNegative($username);
		} elseif ($error === 'config') {
			$this->logger?->error('username resolve config', [
				'detail' => (string) ($data['detail'] ?? ''),
			]);
		} else {
			$this->logger?->error('username resolve failed', [
				'username' => $username,
				'error' => $error,
				'detail' => (string) ($data['detail'] ?? ''),
				'code' => $code,
			]);
		}
		return null;
	}

	/**
		@return array{0: int, 1: string, 2: string}
	*/
	protected function invoke(string $username): array {
		if ($this->runner !== null) {
			return ($this->runner)($username);
		}
		return $this->runPython($username);
	}

	/**
		@return array{0: int, 1: string, 2: string}
	*/
	protected function runPython(string $username): array {
		$python = (string) ($this->config['python'] ?? 'python3.11');
		$script = (string) $this->config['script'];
		$session_dir = (string) ($this->config['session_dir'] ?? '');
		$timeout = max(1, (int) ($this->config['timeout'] ?? 10));

		$cmd = [$python, $script, $username];
		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$env = $_ENV + $_SERVER;
		$env['TG_API_ID'] = (string) ($this->config['api_id'] ?? '');
		$env['TG_API_HASH'] = (string) ($this->config['api_hash'] ?? '');
		$env['TELEGRAM_BOT_TOKEN'] = (string) ($this->config['token'] ?? '');
		if ($session_dir !== '') {
			$env['P1BOT_SESSION_DIR'] = $session_dir;
		}
		if (!empty($this->config['root'])) {
			$env['P1BOT_ROOT'] = (string) $this->config['root'];
		}
		// Ensure PATH-ish keys are strings for proc_open.
		$clean = [];
		foreach ($env as $k => $v) {
			if (is_string($k) && (is_string($v) || is_int($v) || is_float($v))) {
				$clean[$k] = (string) $v;
			}
		}

		$proc = proc_open($cmd, $descriptors, $pipes, null, $clean);
		if (!is_resource($proc)) {
			return [4, '', 'proc_open failed'];
		}
		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$stdout = '';
		$stderr = '';
		$deadline = microtime(true) + $timeout;
		while (true) {
			$read = [$pipes[1], $pipes[2]];
			$write = null;
			$except = null;
			$left = $deadline - microtime(true);
			if ($left <= 0) {
				proc_terminate($proc, 9);
				$stdout .= stream_get_contents($pipes[1]) ?: '';
				$stderr .= stream_get_contents($pipes[2]) ?: '';
				$stderr .= "\ntimeout after {$timeout}s";
				fclose($pipes[1]);
				fclose($pipes[2]);
				proc_close($proc);
				return [4, $stdout, $stderr];
			}
			$sec = (int) $left;
			$usec = (int) (($left - $sec) * 1_000_000);
			$n = @stream_select($read, $write, $except, $sec, $usec);
			if ($n === false) {
				break;
			}
			foreach ($read as $stream) {
				$chunk = fread($stream, 8192);
				if ($chunk === false || $chunk === '') {
					continue;
				}
				if ($stream === $pipes[1]) {
					$stdout .= $chunk;
				} else {
					$stderr .= $chunk;
				}
			}
			$status = proc_get_status($proc);
			if (!$status['running']) {
				$stdout .= stream_get_contents($pipes[1]) ?: '';
				$stderr .= stream_get_contents($pipes[2]) ?: '';
				break;
			}
		}
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($proc);
		return [$code, $stdout, $stderr];
	}

	protected function cachePath(): string {
		$dir = (string) ($this->config['session_dir'] ?? sys_get_temp_dir());
		return rtrim($dir, '/') . '/username_resolve_cache.json';
	}

	protected function floodPath(): string {
		$dir = (string) ($this->config['session_dir'] ?? sys_get_temp_dir());
		return rtrim($dir, '/') . '/username_resolve_flood_until';
	}

	protected function floodBlocked(): bool {
		$path = $this->floodPath();
		if (!is_file($path)) {
			return false;
		}
		$until = (int) trim((string) @file_get_contents($path));
		if ($until <= $this->clock->now()->getTimestamp()) {
			@unlink($path);
			return false;
		}
		return true;
	}

	protected function rememberFlood(int $retry_after): void {
		$dir = dirname($this->floodPath());
		if (!is_dir($dir)) {
			@mkdir($dir, 0700, true);
		}
		$until = $this->clock->now()->getTimestamp() + max(1, $retry_after);
		@file_put_contents($this->floodPath(), (string) $until);
		@chmod($this->floodPath(), 0600);
	}

	protected function readNegative(string $username): ?int {
		$path = $this->cachePath();
		if (!is_file($path)) {
			return null;
		}
		$data = json_decode((string) file_get_contents($path), true);
		if (!is_array($data)) {
			return null;
		}
		$key = mb_strtolower($username);
		if (!isset($data[$key])) {
			return null;
		}
		$until = (int) $data[$key];
		if ($until <= $this->clock->now()->getTimestamp()) {
			unset($data[$key]);
			@file_put_contents($path, (string) json_encode($data));
			return null;
		}
		return $until;
	}

	protected function writeNegative(string $username): void {
		$path = $this->cachePath();
		$dir = dirname($path);
		if (!is_dir($dir)) {
			@mkdir($dir, 0700, true);
		}
		$data = [];
		if (is_file($path)) {
			$decoded = json_decode((string) file_get_contents($path), true);
			if (is_array($decoded)) {
				$data = $decoded;
			}
		}
		$ttl = (int) ($this->config['negative_ttl'] ?? self::NEGATIVE_TTL);
		$data[mb_strtolower($username)] = $this->clock->now()->getTimestamp() + max(1, $ttl);
		@file_put_contents($path, (string) json_encode($data));
		@chmod($path, 0600);
	}
}
