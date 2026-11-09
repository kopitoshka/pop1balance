<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Model\Player;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Clock;
use P1Bot\Telegram\TgUser;

/**
	Turns "@vasya", a text_mention user or "я/me" into a player row.
*/
class PlayerResolver {

	public function __construct(
		protected PlayerRepository $players,
		protected Clock $clock,
	) {
	}

	public function fromUser(TgUser $user): Player {
		return $this->players->ensureByTelegram($user->id, $user->username, $user->displayName(), $this->clock->now());
	}

	/**
		@param Player $actor used for "я", "me", "себя"
	*/
	public function fromReference(string $reference, Player $actor): Player {
		$ref = trim($reference);
		if (in_array(mb_strtolower($ref), ['я', 'меня', 'себя', 'me', 'self'], true)) {
			return $actor;
		}
		// Telegram @username
		if (str_starts_with($ref, '@') || preg_match('/^[A-Za-z][A-Za-z0-9_]{3,31}$/', $ref)) {
			$username = ltrim($ref, '@');
			if (preg_match('/^[A-Za-z][A-Za-z0-9_]{3,31}$/', $username)) {
				return $this->players->ensureByUsername($username, $this->clock->now());
			}
		}
		// In-game nick (may contain spaces / dots)
		$by_ign = $this->players->findByIgnNorm(mb_strtolower(trim(preg_replace('/\s+/u', ' ', $ref) ?? ''), 'UTF-8'));
		if ($by_ign !== null) {
			return $by_ign;
		}
		$by_name = $this->players->findByUsername(ltrim($ref, '@'));
		if ($by_name !== null) {
			return $by_name;
		}
		throw new ValidationError(sprintf(
			'Не могу найти игрока «%s». Укажи @username или игровой ник.',
			$ref,
		));
	}
}
