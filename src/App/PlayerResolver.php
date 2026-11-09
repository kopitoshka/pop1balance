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
		throw new ValidationError(sprintf(
			'Не могу найти игрока «%s». Укажи @username человека, который уже писал мне, или игровой ник.',
			$ref,
		));
	}

	/**
		@param array<string, mixed>|null $resume
	*/
	protected function throwUnknownOrSuggest(
		string $username,
		Player $actor,
		?array $resume,
		?int $chat_id,
	): never {
		$suggestions = $this->players->fuzzySuggest($username, 5);
		if ($suggestions === []) {
			throw new ValidationError($this->unknownUsernameMessage($username));
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
