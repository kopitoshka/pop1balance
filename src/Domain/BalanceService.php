<?php

declare(strict_types=1);

namespace P1Bot\Domain;

use P1Bot\Domain\Exception\NotFound;
use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Model\Balance;
use P1Bot\Repository\BalanceRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Clock;
use P1Bot\Support\Database;

/**
	Rating scales ("балансы"). Each admin owns their scales; a scale may inherit from a parent:
	a player's level is looked up in the scale, then up the parent chain, otherwise Levels::DEFAULT.
*/
class BalanceService {
	public const DEFAULT_NAME = 'Баланс по умолчанию';

	public function __construct(
		protected BalanceRepository $balances,
		protected PlayerRepository $players,
		protected AccessPolicy $access,
		protected Database $db,
		protected Clock $clock,
	) {
	}

	public function get(int $balance_id): Balance {
		return $this->balances->find($balance_id) ?? throw new NotFound('Такой шкалы баланса нет.');
	}

	public function findDefault(): ?Balance {
		return $this->balances->findDefault();
	}

	/**
		Scale used for /level and for team point limits — always the default (global) scale.
		Keeps what users see in /level identical to enrollment math. Tournament.balance_id may
		still be stored for owner /balance tooling; enrollment reads this view scale.
	*/
	public function viewScaleId(): ?int {
		return $this->findDefault()?->id;
	}

	/**
		Creates the default scale owned by the bot owner if it does not exist yet.
	*/
	public function ensureDefault(int $owner_player_id): Balance {
		return $this->db->transactional(function () use ($owner_player_id): Balance {
			return $this->balances->findDefault()
				?? $this->balances->create($owner_player_id, self::DEFAULT_NAME, null, true, $this->clock->now());
		});
	}

	/**
		New scale owned by an admin, optionally inheriting ratings from $based_on_id.
	*/
	public function create(int $chat_id, int $actor_id, string $name, ?int $based_on_id = null): Balance {
		// Owner only for now: see AccessPolicy::LEVELS_OWNER_ONLY.
		$this->access->assertOwner($actor_id);
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 128) {
			throw new ValidationError('Название шкалы должно быть от 1 до 128 символов.');
		}
		if ($based_on_id !== null) {
			$this->get($based_on_id);
		}
		return $this->balances->create($actor_id, $name, $based_on_id, false, $this->clock->now());
	}

	/**
		Sets a player's level. Without $balance_id the actor's own scale is used; an admin who has
		no scale yet gets one inheriting from the default scale. Only the owner may edit a scale.
	*/
	public function setLevel(
		int $chat_id,
		int $actor_id,
		int $player_id,
		int $level,
		?int $balance_id = null,
	): Balance {
		Levels::assertValid($level);
		// Owner only for now: see AccessPolicy::LEVELS_OWNER_ONLY.
		$this->access->assertOwner($actor_id, AccessPolicy::LEVELS_OWNER_ONLY);
		if ($this->players->find($player_id) === null) {
			throw new NotFound('Такого игрока я не знаю.');
		}

		return $this->db->transactional(function () use ($actor_id, $player_id, $level, $balance_id): Balance {
			// No id → public/default scale (same one /level and team points read).
			$balance = $balance_id === null
				? ($this->findDefault() ?? $this->ownScaleFor($actor_id))
				: $this->get($balance_id);
			if ($balance->owner_id !== $actor_id) {
				throw new PermissionDenied(sprintf(
					'Шкала «%s» принадлежит другому админу, её менять нельзя.',
					$balance->name,
				));
			}
			$this->balances->setRating($balance->id, $player_id, $level, $actor_id, $this->clock->now());
			return $balance;
		});
	}

	/**
		Removes the rating from the actor's scale so the inherited (or default) level applies again.
	*/
	public function resetLevel(int $chat_id, int $actor_id, int $player_id, int $balance_id): void {
		$this->access->assertOwner($actor_id, AccessPolicy::LEVELS_OWNER_ONLY);
		$balance = $this->get($balance_id);
		if ($balance->owner_id !== $actor_id) {
			throw new PermissionDenied(sprintf(
				'Шкала «%s» принадлежит другому админу, её менять нельзя.',
				$balance->name,
			));
		}
		$this->balances->deleteRating($balance_id, $player_id);
	}

	/**
		Effective level: scale, then parents, then Levels::DEFAULT. No scale => DEFAULT.
	*/
	public function levelOf(?int $balance_id, int $player_id): int {
		return $this->explicitLevel($balance_id, $player_id) ?? Levels::DEFAULT;
	}

	/**
		Rating from the scale chain, or null when the player is not rated (caller uses Levels::DEFAULT).
	*/
	public function explicitLevel(?int $balance_id, int $player_id): ?int {
		foreach ($this->chain($balance_id) as $balance) {
			$level = $this->balances->findRating($balance->id, $player_id);
			if ($level !== null) {
				return $level;
			}
		}
		return null;
	}

	/**
		All explicitly rated players visible in the scale (own ratings override inherited ones).

		@return array<int, array{level: int, balance_id: int}> player_id => level and the scale it comes from
	*/
	public function effectiveRatings(int $balance_id): array {
		$result = [];
		foreach (array_reverse($this->chain($balance_id)) as $balance) {
			foreach ($this->balances->ratings($balance->id) as $player_id => $level) {
				$result[$player_id] = ['level' => $level, 'balance_id' => $balance->id];
			}
		}
		ksort($result);
		return $result;
	}

	/**
		@return list<Balance> the scale itself first, then its ancestors (cycle-safe).
	*/
	protected function chain(?int $balance_id): array {
		$chain = [];
		$seen = [];
		while ($balance_id !== null && !isset($seen[$balance_id])) {
			$seen[$balance_id] = true;
			$balance = $this->balances->find($balance_id);
			if ($balance === null) {
				break;
			}
			$chain[] = $balance;
			$balance_id = $balance->parent_id;
		}
		// Ultimate fallback: ratings on the default scale apply even if the tournament
		// points at an unrelated empty scale (keeps /level and team points aligned).
		$default = $this->balances->findDefault();
		if ($default !== null && !isset($seen[$default->id])) {
			$chain[] = $default;
		}
		return $chain;
	}

	protected function ownScaleFor(int $actor_id): Balance {
		$default = $this->balances->findDefault();
		if ($default !== null && $default->owner_id === $actor_id) {
			return $default;
		}
		$own = $this->balances->listByOwner($actor_id);
		if ($own !== []) {
			return $own[0];
		}
		$player = $this->players->find($actor_id);
		$name = 'Баланс ' . ($player?->mention() ?? '#' . $actor_id);
		return $this->balances->create($actor_id, $name, $default?->id, false, $this->clock->now());
	}
}
