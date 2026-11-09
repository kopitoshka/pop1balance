<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Model\Player;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Clock;
use P1Bot\Telegram\TgUser;
use P1Bot\View\DeepLink;

/**
	Turns "@vasya", a text_mention user or "я/me" into a known player (with Telegram id).
	Never creates placeholder rows without tg_user_id.
*/
class PlayerResolver {

	public function __construct(
		protected PlayerRepository $players,
		protected Clock $clock,
		protected string $bot_username = '',
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
		// Telegram @username — only an existing player who already talked to the bot / was seen.
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
				throw new ValidationError($this->unknownUsernameMessage($username));
			}
		}
		// In-game nick (may contain spaces / dots) — only if linked to a real Telegram user.
		$by_ign = $this->players->findByIgnNorm(mb_strtolower(trim(preg_replace('/\s+/u', ' ', $ref) ?? ''), 'UTF-8'));
		if ($by_ign !== null && $by_ign->tg_user_id !== null) {
			return $by_ign;
		}
		if ($by_ign !== null && $by_ign->tg_user_id === null) {
			$label = $by_ign->username !== null ? '@' . $by_ign->username : $ref;
			throw new ValidationError($this->unknownUsernameMessage(ltrim($label, '@')));
		}
		throw new ValidationError(sprintf(
			'Не могу найти игрока «%s». Укажи @username человека, который уже писал мне, или игровой ник.',
			$ref,
		));
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
