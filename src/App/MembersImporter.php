<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Repository\ChatRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Clock;
use P1Bot\Telegram\TelegramApi;
use P1Bot\Telegram\TelegramError;
use Throwable;

/**
	Imports current group members from Telegram Desktop export user ids via getChatMember.
*/
class MembersImporter {
	public const ACTIVE_STATUSES = ['creator', 'administrator', 'member', 'restricted'];
	public const LEFT_STATUSES = ['left', 'kicked'];

	/** Default max getChatMember calls per second. */
	public const RATE_PER_SEC = 20;

	/** @var callable(int): void */
	protected $sleep;

	/**
		@param callable(int): void|null $sleep sleep seconds (injectable for tests)
		@param int $rate_per_sec max getChatMember calls per second (tests may raise)
	*/
	public function __construct(
		protected TelegramApi $api,
		protected PlayerRepository $players,
		protected ChatRepository $chats,
		protected Clock $clock,
		$sleep = null,
		protected int $rate_per_sec = self::RATE_PER_SEC,
	) {
		$this->sleep = $sleep ?? static function (int $seconds): void {
			if ($seconds > 0) {
				sleep($seconds);
			}
		};
		if ($this->rate_per_sec < 1) {
			$this->rate_per_sec = 1;
		}
	}

	/**
		@param list<int> $user_ids
		@return array{
			chat_id: int,
			chat_title: string,
			total_ids: int,
			imported_new: int,
			updated_existing: int,
			merged_placeholders: int,
			left_or_kicked: int,
			bots_skipped: int,
			errors: int,
			error_samples: list<string>,
			dry_run: bool
		}
	*/
	public function import(int $chat_id, array $user_ids, bool $dry_run = false): array {
		$chat = $this->api->getChat($chat_id);
		$title = (string) ($chat['title'] ?? $chat['username'] ?? ('chat ' . $chat_id));
		if (!$dry_run) {
			$this->chats->ensure($chat_id, $title);
		}

		$stats = [
			'chat_id' => $chat_id,
			'chat_title' => $title,
			'total_ids' => count($user_ids),
			'imported_new' => 0,
			'updated_existing' => 0,
			'merged_placeholders' => 0,
			'left_or_kicked' => 0,
			'bots_skipped' => 0,
			'errors' => 0,
			'error_samples' => [],
			'dry_run' => $dry_run,
		];

		$min_interval_us = (int) floor(1_000_000 / $this->rate_per_sec);
		$last_call_us = 0;

		foreach ($user_ids as $user_id) {
			$now_us = (int) (microtime(true) * 1_000_000);
			$wait_us = $min_interval_us - ($now_us - $last_call_us);
			if ($wait_us > 0) {
				usleep($wait_us);
			}
			$last_call_us = (int) (microtime(true) * 1_000_000);

			try {
				$member = $this->getChatMemberWithRetry($chat_id, $user_id);
			} catch (Throwable $e) {
				$stats['errors']++;
				if (count($stats['error_samples']) < 10) {
					$stats['error_samples'][] = sprintf('user %d: %s', $user_id, $e->getMessage());
				}
				continue;
			}

			$user = $member['user'] ?? null;
			if (!is_array($user)) {
				$stats['errors']++;
				continue;
			}
			if (!empty($user['is_bot'])) {
				$stats['bots_skipped']++;
				continue;
			}

			$status = (string) ($member['status'] ?? '');
			if (in_array($status, self::LEFT_STATUSES, true)) {
				$stats['left_or_kicked']++;
				continue;
			}
			if (!in_array($status, self::ACTIVE_STATUSES, true)) {
				$stats['left_or_kicked']++;
				continue;
			}

			// restricted: still in chat unless is_member is explicitly false
			if ($status === 'restricted' && array_key_exists('is_member', $member) && !$member['is_member']) {
				$stats['left_or_kicked']++;
				continue;
			}

			$tg_id = (int) ($user['id'] ?? $user_id);
			$username = isset($user['username']) && $user['username'] !== ''
				? (string) $user['username']
				: null;
			$display = trim(
				(string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''),
			);
			if ($display === '') {
				$display = $username ?? ('user' . $tg_id);
			}

			if ($dry_run) {
				$existing = $this->players->findByTelegramId($tg_id);
				if ($existing === null) {
					$ph = $username !== null ? $this->players->findByUsername($username) : null;
					if ($ph !== null && $ph->tg_user_id === null) {
						$stats['merged_placeholders']++;
					} else {
						$stats['imported_new']++;
					}
				} else {
					$stats['updated_existing']++;
				}
				continue;
			}

			$before = $this->players->findByTelegramId($tg_id);
			$placeholder = null;
			if ($before === null && $username !== null) {
				$placeholder = $this->players->findByUsername($username);
				if ($placeholder !== null && $placeholder->tg_user_id !== null) {
					$placeholder = null;
				}
			}
			$this->players->ensureByTelegram($tg_id, $username, $display, $this->clock->now());
			if ($before === null && $placeholder !== null) {
				$stats['merged_placeholders']++;
			} elseif ($before === null) {
				$stats['imported_new']++;
			} else {
				$stats['updated_existing']++;
			}
		}

		return $stats;
	}

	/**
		@return array<string, mixed>
	*/
	protected function getChatMemberWithRetry(int $chat_id, int $user_id): array {
		$attempts = 0;
		while (true) {
			$attempts++;
			try {
				return $this->api->getChatMember($chat_id, $user_id);
			} catch (TelegramError $e) {
				$retry = $e->retryAfter();
				if ($retry !== null && $attempts <= 5) {
					($this->sleep)($retry);
					continue;
				}
				throw $e;
			}
		}
	}
}
