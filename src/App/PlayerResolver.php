<?php

declare(strict_types=1);

namespace P1Bot\App;

use DateInterval;
use P1Bot\Domain\Exception\PlayerSuggestError;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Model\Player;
use P1Bot\Repository\PendingActionRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Clock;
use P1Bot\Telegram\TgUser;
use P1Bot\View\DeepLink;

/**
	Turns "@vasya", a text_mention user or "я/me" into a known player (with Telegram id).
	Never creates placeholder rows without tg_user_id.
	Unknown @username: MTProto (if configured) → fuzzy suggestions with pick buttons.
*/
class PlayerResolver {
	public const SUGGEST_TTL_SECONDS = 600;

	public function __construct(
		protected PlayerRepository $players,
		protected Clock $clock,
		protected string $bot_username = '',
		protected ?UsernameLookup $username_lookup = null,
		protected ?PendingActionRepository $pending = null,
	) {
	}

	public function fromUser(TgUser $user): Player {
		return $this->players->ensureByTelegram($user->id, $user->username, $user->displayName(), $this->clock->now());
	}

	/**
		@param Player $actor used for "я", "me", "себя"
		@param array<string, mixed>|null $resume pending action payload if we suggest
	*/
	public function fromReference(
		string $reference,
		Player $actor,
		?array $resume = null,
		?int $chat_id = null,
	): Player {
		$ref = trim($reference);
		if (in_array(mb_strtolower($ref), ['я', 'меня', 'себя', 'me', 'self'], true)) {
			return $actor;
		}
		// Telegram @username — known player, or MTProto resolve when enabled.
		if (str_starts_with($ref, '@') || preg_match('/^[A-Za-z][A-Za-z0-9_]{2,31}$/', $ref)) {
			$username = ltrim($ref, '@');
			if (preg_match('/^[A-Za-z][A-Za-z0-9_]{2,31}$/', $username)) {
				if ($actor->username !== null && strcasecmp($actor->username, $username) === 0) {
					return $actor;
				}
				$known = $this->players->findKnownByUsername($username);
				if ($known !== null) {
					return $known;
				}
				$resolved = $this->tryMtproto($username);
				if ($resolved !== null) {
					return $resolved;
				}
				$this->throwUnknownOrSuggest($username, $actor, $resume, $chat_id);
			}
		}
		// In-game nick (may contain spaces / dots) — only if linked to a real Telegram user.
		$by_ign = $this->players->findByIgnNorm(mb_strtolower(trim(preg_replace('/\s+/u', ' ', $ref) ?? ''), 'UTF-8'));
		if ($by_ign !== null && $by_ign->tg_user_id !== null) {
			return $by_ign;
		}
		if ($by_ign !== null && $by_ign->tg_user_id === null) {
			$label = $by_ign->username !== null ? '@' . $by_ign->username : $ref;
			$this->throwUnknownOrSuggest(ltrim($label, '@'), $actor, $resume, $chat_id);
		}
		$exact = $this->uniqueExact($ref);
		if ($exact !== null) {
			return $exact;
		}
		$this->throwUnknownOrSuggest($ref, $actor, $resume, $chat_id, false);
	}

	/**
		Resolve without throwing: exact match or null plus name/nick/username suggestions.
		@param list<int> $exclude_ids
		@return array{player: ?Player, suggestions: list<Player>}
	*/
	public function tryResolve(string $reference, Player $actor, array $exclude_ids = []): array {
		$ref = trim($reference);
		if ($ref === '') {
			return ['player' => null, 'suggestions' => []];
		}
		try {
			$player = $this->fromReference($ref, $actor, null, null);
		} catch (PlayerSuggestError $e) {
			return ['player' => null, 'suggestions' => $this->filterExcluded($e->suggestions, $exclude_ids)];
		} catch (ValidationError $e) {
			unset($e);
			return ['player' => null, 'suggestions' => $this->suggestFor($ref, $exclude_ids)];
		}
		if (in_array($player->id, $exclude_ids, true)) {
			return ['player' => null, 'suggestions' => $this->suggestFor($ref, $exclude_ids)];
		}
		return ['player' => $player, 'suggestions' => []];
	}

	/**
		Partial + fuzzy matches by username, display name or IGN.
		@param list<int> $exclude_ids
		@return list<Player>
	*/
	public function suggestFor(string $reference, array $exclude_ids = [], int $limit = 5): array {
		$ref = trim($reference);
		if ($ref === '') {
			return [];
		}
		$seen = array_fill_keys($exclude_ids, true);
		$out = [];
		foreach ($this->players->searchPartial($ref, $limit, $exclude_ids) as $player) {
			if (isset($seen[$player->id])) {
				continue;
			}
			$seen[$player->id] = true;
			$out[] = $player;
		}
		if (count($out) < $limit) {
			foreach ($this->players->fuzzySuggest($ref, $limit) as $player) {
				if (isset($seen[$player->id])) {
					continue;
				}
				$seen[$player->id] = true;
				$out[] = $player;
				if (count($out) >= $limit) {
					break;
				}
			}
		}
		return $out;
	}

	/**
		@param list<Player> $players
		@param list<int> $exclude_ids
		@return list<Player>
	*/
	/**
		Unique exact match on display name, IGN or username (known Telegram users only).
	*/
	protected function uniqueExact(string $ref): ?Player {
		$needle = mb_strtolower(ltrim(trim($ref), '@'));
		if ($needle === '') {
			return null;
		}
		$hits = [];
		foreach ($this->players->searchPartial($ref, 8) as $player) {
			$fields = [
				mb_strtolower((string) ($player->display_name ?? '')),
				mb_strtolower((string) ($player->ign ?? '')),
				mb_strtolower((string) ($player->username ?? '')),
			];
			if (in_array($needle, $fields, true)) {
				$hits[$player->id] = $player;
			}
		}
		return count($hits) === 1 ? array_values($hits)[0] : null;
	}

	protected function filterExcluded(array $players, array $exclude_ids): array {
		if ($exclude_ids === []) {
			return $players;
		}
		$skip = array_fill_keys($exclude_ids, true);
		$out = [];
		foreach ($players as $player) {
			if (!isset($skip[$player->id])) {
				$out[] = $player;
			}
		}
		return $out;
	}

	/**
		@param array<string, mixed>|null $resume
	*/
	protected function throwUnknownOrSuggest(
		string $username,
		Player $actor,
		?array $resume,
		?int $chat_id,
		bool $as_username = true,
	): never {
		$suggestions = $this->suggestFor($username);
		if ($suggestions === []) {
			throw new ValidationError(
				$as_username && preg_match('/^[A-Za-z][A-Za-z0-9_]{2,31}$/', ltrim($username, '@'))
					? $this->unknownUsernameMessage($username)
					: sprintf(
						'Не могу найти игрока «%s». Укажи @username человека, который уже писал мне, или игровой ник.',
						$username,
					),
			);
		}
		$pending_id = null;
		if ($this->pending !== null && $resume !== null && $chat_id !== null) {
			$now = $this->clock->now();
			$pending_id = $this->pending->create(
				$actor->id,
				$chat_id,
				$resume,
				$now->add(new DateInterval('PT' . self::SUGGEST_TTL_SECONDS . 'S')),
				$now,
			);
		}
		$lines = ['@' . $username . ' не нашла среди знакомых. Может, ты про кого-то из этих?'];
		throw new PlayerSuggestError(implode("\n", $lines), $suggestions, $pending_id);
	}

	protected function tryMtproto(string $username): ?Player {
		if ($this->username_lookup === null || !$this->username_lookup->isAvailable()) {
			return null;
		}
		$info = $this->username_lookup->resolve($username);
		if ($info === null) {
			return null;
		}
		$display = trim($info['first_name'] . ' ' . $info['last_name']);
		if ($display === '') {
			$display = $info['username'] ?? ('user' . $info['id']);
		}
		return $this->players->ensureByTelegram(
			$info['id'],
			$info['username'],
			$display,
			$this->clock->now(),
		);
	}

	protected function unknownUsernameMessage(string $username): string {
		$username = ltrim($username, '@');
		$link = $this->bot_username !== ''
			? DeepLink::url($this->bot_username)
			: '/start';
		return sprintf(
			'@%s ещё не знаком со мной — пусть напишет мне в личку (%s) и нажмёт /start.',
			$username,
			$link,
		);
	}
}
