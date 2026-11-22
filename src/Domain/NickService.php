<?php

declare(strict_types=1);

namespace P1Bot\Domain;

use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Model\Player;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Clock;
use P1Bot\Support\Database;

/**
	Population: One in-game nicknames (IGN), with or without a Telegram account.
*/
class NickService {
	public const MIN_LEN = 2;
	public const MAX_LEN = 32;

	public const TAKEN = 'Этот ник уже занят другим игроком. Если это твой ник — напиши главному админу';

	public function __construct(
		protected PlayerRepository $players,
		protected AccessPolicy $access,
		protected Database $db,
		protected Clock $clock,
	) {
	}

	/**
		Validate and normalize display form. Returns [ign, ign_norm].
		@param array{0: string, 1: string}
	*/
	public static function parse(string $raw): array {
		$ign = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
		if ($ign === '') {
			throw new ValidationError(
				'Укажи игровой ник, например: /nick Zoloto on heat',
			);
		}
		$len = mb_strlen($ign);
		if ($len < self::MIN_LEN || $len > self::MAX_LEN) {
			throw new ValidationError(sprintf(
				'Ник от %d до %d символов. Сейчас %d.',
				self::MIN_LEN,
				self::MAX_LEN,
				$len,
			));
		}
		// Letters, digits, space, underscore, dot (as in Po1 IGNs).
		if (!preg_match('/^[\p{L}\p{N} _.\-]+$/u', $ign)) {
			throw new ValidationError(
				'В нике можно буквы, цифры, пробел, _ , . и -.',
			);
		}
		return [$ign, mb_strtolower($ign, 'UTF-8')];
	}

	public function findByIgn(string $raw): ?Player {
		try {
			[, $norm] = self::parse($raw);
		} catch (ValidationError $e) {
			return null;
		}
		return $this->players->findByIgnNorm($norm);
	}

	/**
		Player sets or changes own nick. Refuses if taken by someone else.
	*/
	public function setOwn(int $player_id, string $raw): Player {
		[$ign, $norm] = self::parse($raw);
		return $this->db->transactional(function () use ($player_id, $ign, $norm): Player {
			$holder = $this->players->findByIgnNorm($norm);
			if ($holder !== null && $holder->id !== $player_id) {
				throw new ValidationError(self::TAKEN);
			}
			return $this->assign($player_id, $ign, $norm, $player_id);
		});
	}

	/**
		Owner assigns a nick to anyone, taking it from another player if needed.
		@return array{player: Player, previous_holder: ?Player}
	*/
	public function assignByOwner(int $owner_id, int $target_id, string $raw): array {
		$this->access->assertSuper($owner_id);
		[$ign, $norm] = self::parse($raw);
		return $this->db->transactional(function () use ($owner_id, $target_id, $ign, $norm): array {
			$holder = $this->players->findByIgnNorm($norm);
			$previous = null;
			if ($holder !== null && $holder->id !== $target_id) {
				$previous = $holder;
				$this->clear($holder->id, $owner_id);
			}
			$player = $this->assign($target_id, $ign, $norm, $owner_id);
			return ['player' => $player, 'previous_holder' => $previous];
		});
	}

	/**
		Player card (owner): sets the nick, or clears it for an empty $raw. A nick another player holds
		is refused: two cards with one nick are duplicates, the owner merges them instead. The old nick
		goes to the history.
	*/
	public function setByOwner(int $owner_id, int $target_id, ?string $raw): Player {
		$this->access->assertSuper($owner_id);
		if ($raw === null || trim($raw) === '') {
			return $this->db->transactional(function () use ($owner_id, $target_id): Player {
				$this->clear($target_id, $owner_id);
				return $this->players->find($target_id) ?? throw new ValidationError('Такого игрока я не знаю.');
			});
		}
		[$ign, $norm] = self::parse($raw);
		return $this->db->transactional(function () use ($owner_id, $target_id, $ign, $norm): Player {
			$holder = $this->players->findByIgnNorm($norm);
			if ($holder !== null && $holder->id !== $target_id) {
				throw new ValidationError(sprintf(
					'Ник «%s» уже у игрока %s. Если это дубль — объедини их.',
					$holder->ign,
					$holder->plainLabel(),
				));
			}
			return $this->assign($target_id, $ign, $norm, $owner_id);
		});
	}

	/**
		/addplayer with arguments; the caller checks access (owner or CreateAccess).
		Username may be null (nick-only); nick may be null (username-only). An existing @username is
		reused and gets the nick with the taken-nick override of assignByOwner (history kept).
		A nick-only player never takes a nick from someone: a taken nick is refused.
		Atomic: an invalid or taken nick never leaves a half-created row.
		@return array{player: Player, existed: bool, previous_holder: ?Player}
	*/
	public function addPlayer(int $actor_id, ?string $username, ?string $raw): array {
		if ($username !== null) {
			$username = PlayerRepository::normalizeUsername($username);
			if (!preg_match('/^[a-z][a-z0-9_]{4,31}$/D', $username)) {
				throw new ValidationError('Username: 5–32 символа, латинские буквы, цифры и _, первая — буква. Например: @player.');
			}
		}
		if ($raw === null && $username === null) {
			throw new ValidationError('Укажи @username или игровой ник.');
		}
		[$ign, $norm] = $raw === null ? [null, null] : self::parse($raw);
		return $this->db->transactional(function () use ($actor_id, $username, $ign, $norm): array {
			$player = $username === null ? null : $this->players->findByUsername($username);
			$existed = $player !== null;
			$previous = null;
			if ($norm !== null) {
				$holder = $this->players->findByIgnNorm($norm);
				if ($holder !== null && $username === null) {
					throw new ValidationError(sprintf(
						'Ник «%s» уже у игрока %s. Если это другой человек, дай ему другой ник.',
						$holder->ign,
						$holder->plainLabel(),
					));
				}
				if ($holder !== null && $holder->id !== $player?->id) {
					$previous = $holder;
					$this->clear($holder->id, $actor_id);
				}
			}
			if ($player === null) {
				$player = $this->players->createOffline($username, $ign, $norm, $this->clock->now());
			} elseif ($ign !== null) {
				$player = $this->assign($player->id, $ign, (string) $norm, $actor_id);
			}
			return ['player' => $player, 'existed' => $existed, 'previous_holder' => $previous];
		});
	}

	protected function assign(int $player_id, string $ign, string $norm, int $changed_by): Player {
		$current = $this->players->find($player_id)
			?? throw new ValidationError('Такого игрока я не знаю.');
		if ($current->ign_norm === $norm) {
			// Same nick (possibly different casing) — just refresh display form.
			if ($current->ign !== $ign) {
				$this->players->updateIgn($player_id, $ign, $norm);
			}
			return $this->players->find($player_id) ?? $current;
		}
		if ($current->ign !== null && $current->ign_norm !== null) {
			$this->players->recordIgnHistory(
				$player_id,
				$current->ign,
				$current->ign_norm,
				$this->clock->now(),
				$changed_by,
			);
		}
		$this->players->updateIgn($player_id, $ign, $norm);
		return $this->players->find($player_id) ?? throw new \LogicException('Player vanished');
	}

	protected function clear(int $player_id, int $changed_by): void {
		$current = $this->players->find($player_id);
		if ($current === null || $current->ign === null || $current->ign_norm === null) {
			return;
		}
		$this->players->recordIgnHistory(
			$player_id,
			$current->ign,
			$current->ign_norm,
			$this->clock->now(),
			$changed_by,
		);
		$this->players->updateIgn($player_id, null, null);
	}
}
