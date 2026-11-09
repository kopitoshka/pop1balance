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
	Population: One in-game nicknames (IGN) linked to Telegram players.
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
		$this->access->assertOwner($owner_id);
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
