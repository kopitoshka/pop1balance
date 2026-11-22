<?php

declare(strict_types=1);

namespace P1Bot\Domain\Model;

use DateTimeImmutable;
use P1Bot\Domain\Exception\InvalidLevel;
use P1Bot\Domain\Levels;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Support\DateFormat;

class Tournament {

	public function __construct(
		public int $id,
		public ?int $chat_id,
		public int $organizer_id,
		public string $title,
		public DateTimeImmutable $starts_at,
		public int $team_size,
		public ?int $balance_id,
		public ?string $reference_team,
		public ?int $max_points,
		public int $max_players,
		public string $status,
		public ?int $announce_msg_id,
		public ?DateTimeImmutable $reminded_at,
		public ?int $card_msg_id = null,
		public ?int $card_shown_to = null,
		public ?string $image_path = null,
		public ?string $map = null,
		public ?string $description = null,
	) {
	}

	/**
		Global number users see and type (№1, №2, ...): the tournament id, set by TournamentResolver::number().
	*/
	public ?int $number = null;

	/**
		@param array<string, mixed> $row
	*/
	public static function fromRow(array $row): self {
		return new self(
			(int) $row['id'],
			$row['chat_id'] === null ? null : (int) $row['chat_id'],
			(int) $row['organizer_id'],
			(string) $row['title'],
			DateFormat::fromDb((string) $row['starts_at']),
			(int) $row['team_size'],
			$row['balance_id'] === null ? null : (int) $row['balance_id'],
			$row['reference_team'] === null ? null : (string) $row['reference_team'],
			$row['max_points'] === null ? null : (int) $row['max_points'],
			(int) $row['max_players'],
			TournamentStatus::assertValid((string) $row['status']),
			$row['announce_msg_id'] === null ? null : (int) $row['announce_msg_id'],
			DateFormat::fromDbNullable($row['reminded_at'] === null ? null : (string) $row['reminded_at']),
			isset($row['card_msg_id']) ? (int) $row['card_msg_id'] : null,
			isset($row['card_shown_to']) ? (int) $row['card_shown_to'] : null,
			isset($row['image_path']) && $row['image_path'] !== '' ? (string) $row['image_path'] : null,
			isset($row['map']) && $row['map'] !== '' ? (string) $row['map'] : null,
			isset($row['description']) && $row['description'] !== '' ? (string) $row['description'] : null,
		);
	}

	/**
		Level limit applies only when a balance scale and reference team are set.
	*/
	public function hasLevelLimit(): bool {
		return $this->balance_id !== null && $this->max_points !== null;
	}

	public function averageLevel(): ?int {
		if (!$this->hasLevelLimit()) {
			return null;
		}
		$levels = $this->referenceLevels();
		if ($levels !== null) {
			return Levels::averageLevel(array_sum($levels), count($levels));
		}
		return Levels::averageLevel((int) $this->max_points, $this->team_size);
	}

	/**
		Per-player levels of the reference team, or null when the limit is a plain total.

		@return list<int>|null
	*/
	protected function referenceLevels(): ?array {
		if ($this->reference_team === null || $this->reference_team === '') {
			return null;
		}
		try {
			$levels = Levels::parseReferenceTeam($this->reference_team);
		} catch (InvalidLevel $e) {
			return null;
		}
		return $levels !== [] ? $levels : null;
	}

	/**
		«Макс. уровень: 11» — the points cap only, no player class name — or
		«без ограничения по уровню» when the limit is not set.
	*/
	public function levelLabel(): string {
		if (!$this->hasLevelLimit()) {
			return 'без ограничения по уровню';
		}
		return 'Макс. уровень: ' . (int) $this->max_points;
	}

	public function isRegistrationOpen(): bool {
		return $this->status === TournamentStatus::REGISTRATION;
	}
}
