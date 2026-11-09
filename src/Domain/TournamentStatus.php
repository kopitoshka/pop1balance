<?php

declare(strict_types=1);

namespace P1Bot\Domain;

use InvalidArgumentException;

/**
	Tournament statuses (string constants: PHP 8.0 has no native enums).
*/
class TournamentStatus {
	public const DRAFT = 'draft';
	public const REGISTRATION = 'registration';
	public const CLOSED = 'closed';
	public const RUNNING = 'running';
	public const FINISHED = 'finished';
	public const CANCELLED = 'cancelled';

	protected const LABELS = [
		self::DRAFT => 'черновик',
		self::REGISTRATION => 'идёт регистрация',
		self::CLOSED => 'регистрация закрыта',
		self::RUNNING => 'идёт',
		self::FINISHED => 'завершён',
		self::CANCELLED => 'отменён',
	];

	protected function __construct() {
	}

	/**
		@return list<string>
	*/
	public static function all(): array {
		return array_keys(self::LABELS);
	}

	/**
		@return list<string> statuses shown in list_tournaments
	*/
	public static function active(): array {
		return [self::REGISTRATION, self::CLOSED, self::RUNNING];
	}

	/**
		Statuses that get a global number (the tournament id): everything not finished or cancelled.

		@return list<string>
	*/
	public static function numbered(): array {
		return [self::DRAFT, self::REGISTRATION, self::CLOSED, self::RUNNING];
	}

	public static function assertValid(string $status): string {
		if (!isset(self::LABELS[$status])) {
			throw new InvalidArgumentException("Unknown tournament status: $status");
		}
		return $status;
	}

	public static function label(string $status): string {
		return self::LABELS[self::assertValid($status)];
	}
}
