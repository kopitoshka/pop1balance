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
		$username = ltrim($ref, '@');
		if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{3,31}$/', $username)) {
			// Display names without @username can't be resolved reliably.
			$by_name = $this->players->findByUsername($username);
			if ($by_name !== null) {
				return $by_name;
			}
			throw new ValidationError(sprintf('Не могу найти игрока «%s». Укажи его через @username.', $ref));
		}
		return $this->players->ensureByUsername($username, $this->clock->now());
	}
}
