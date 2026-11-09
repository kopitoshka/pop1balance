<?php

declare(strict_types=1);

namespace P1Bot\Domain;

use P1Bot\Domain\Model\SoloPlayer;
use P1Bot\Domain\Model\TeamEntry;

/**
	Computed main/reserve split of a tournament. Nothing here is stored in the DB.

	Rules: registered teams go first in registered_at order, each fits wholly into the remaining
	places or goes to reserve wholly; then solo players in created_at order fill what is left.
*/
class Lineup {

	/**
		@param list<TeamEntry>  $main_teams
		@param list<TeamEntry>  $reserve_teams
		@param list<SoloPlayer> $main_solo
		@param list<SoloPlayer> $reserve_solo
		@param list<TeamEntry>  $forming_teams teams still assembling, not in the queue
	*/
	protected function __construct(
		public int $max_players,
		public array $main_teams,
		public array $reserve_teams,
		public array $main_solo,
		public array $reserve_solo,
		public array $forming_teams,
	) {
	}

	/**
		@param list<TeamEntry>  $registered_teams already in registered_at order
		@param list<SoloPlayer> $solo_players     already in created_at order
		@param list<TeamEntry>  $forming_teams
	*/
	public static function compute(
		int $max_players,
		array $registered_teams,
		array $solo_players,
		array $forming_teams = [],
	): self {
		$free = $max_players;
		$main_teams = $reserve_teams = $main_solo = $reserve_solo = [];

		foreach ($registered_teams as $entry) {
			if ($entry->size() <= $free) {
				$main_teams[] = $entry;
				$free -= $entry->size();
			} else {
				$reserve_teams[] = $entry;
			}
		}
		foreach ($solo_players as $solo) {
			if ($free > 0) {
				$main_solo[] = $solo;
				$free--;
			} else {
				$reserve_solo[] = $solo;
			}
		}

		return new self($max_players, $main_teams, $reserve_teams, $main_solo, $reserve_solo, $forming_teams);
	}

	/**
		Players in the main lineup.
	*/
	public function occupied(): int {
		return self::teamPlayers($this->main_teams) + count($this->main_solo);
	}

	/**
		Players in reserve (teams counted by members).
	*/
	public function reserveCount(): int {
		return self::teamPlayers($this->reserve_teams) + count($this->reserve_solo);
	}

	public function freePlaces(): int {
		return $this->max_players - $this->occupied();
	}

	/**
		"занято 18/24, резерв 2"
	*/
	public function summary(): string {
		return sprintf('занято %d/%d, резерв %d', $this->occupied(), $this->max_players, $this->reserveCount());
	}

	/**
		'main' | 'reserve' | 'forming' | null — where the player is in this tournament.
	*/
	public function placementOf(int $player_id): ?string {
		foreach ([
			'main' => $this->main_teams,
			'reserve' => $this->reserve_teams,
			'forming' => $this->forming_teams,
		] as $place => $teams) {
			foreach ($teams as $entry) {
				foreach ($entry->members as $member) {
					if ($member->player_id === $player_id) {
						return $place;
					}
				}
			}
		}
		foreach (['main' => $this->main_solo, 'reserve' => $this->reserve_solo] as $place => $solos) {
			foreach ($solos as $solo) {
				if ($solo->player_id === $player_id) {
					return $place;
				}
			}
		}
		return null;
	}

	/**
		@param list<TeamEntry> $teams
	*/
	protected static function teamPlayers(array $teams): int {
		return array_sum(array_map(static fn (TeamEntry $e): int => $e->size(), $teams));
	}
}
