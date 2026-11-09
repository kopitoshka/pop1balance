<?php

declare(strict_types=1);

namespace P1Bot\Domain\Model;

use DateTimeImmutable;
use P1Bot\Domain\Levels;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Support\DateFormat;
use P1Bot\Support\RuPlural;

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
	) {
	}

	/**
		Global number users see and type (№1, №2, ...): the tournament id, set by TournamentResolver::number().
		Null for finished/cancelled tournaments.
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
		);
	}

	/**
		Level limit applies only when a balance scale and reference team are set.
	*/
	public function hasLevelLimit(): bool {
		return $this->balance_id !== null && $this->max_points !== null;
	}

	public function averageLevel(): ?int {
		return $this->hasLevelLimit() ? Levels::averageLevel((int) $this->max_points, $this->team_size) : null;
	}

	/**
		"средний (10 баллов на троих)" or "без ограничения по уровню".
	*/
	public function levelLabel(): string {
		$level = $this->averageLevel();
		if ($level === null) {
			return 'без ограничения по уровню';
		}
		$who = match ($this->team_size) {
			2 => 'двоих',
			3 => 'троих',
			4 => 'четверых',
			default => $this->team_size . ' игроков',
		};
		return sprintf(
			'%s (%d %s на %s)',
			Levels::name($level),
			$this->max_points,
			RuPlural::points((int) $this->max_points),
			$who,
		);
	}

	public function isRegistrationOpen(): bool {
		return $this->status === TournamentStatus::REGISTRATION;
	}
}
