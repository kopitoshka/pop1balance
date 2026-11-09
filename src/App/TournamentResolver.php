<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Domain\Exception\NotFound;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\MoscowTime;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Repository\TournamentRepository;

/**
	Global tournament numbers and lookup.

	The number users see and type (№1, №2, /edit 1) is the tournament's database id — the same
	in every group. Finished and cancelled tournaments drop out of the numbering (null).
*/
class TournamentResolver {

	public function __construct(protected TournamentRepository $tournaments) {
	}

	/**
		@param int|null $chat_id when set, only that group's numbered tournaments (for renumber maps)

		@return array<int, int> real id => number (always equal to the id)
	*/
	public function numbers(?int $chat_id = null): array {
		$numbers = [];
		$list = $chat_id !== null
			? $this->tournaments->listByChat($chat_id, TournamentStatus::numbered())
			: $this->tournaments->listByStatuses(TournamentStatus::numbered());
		foreach ($list as $t) {
			$numbers[$t->id] = $t->id;
		}
		return $numbers;
	}

	/**
		Sets $t->number (null for finished/cancelled) and returns the same object.
	*/
	public function number(Tournament $t): Tournament {
		$t->number = in_array($t->status, TournamentStatus::numbered(), true) ? $t->id : null;
		return $t;
	}

	/**
		"#5" / "5" / "№5" => 5, otherwise null.
	*/
	public static function parseId(string $token): ?int {
		return preg_match('/^[#№]?(\d{1,9})$/u', trim($token), $m) ? (int) $m[1] : null;
	}

	/**
		Resolve by the global number (tournament id). $chat_id is only used when no number is given:
		then candidates are taken from that group; pass null to search every group.

		@param list<string> $statuses candidates when $number is null
	*/
	public function resolve(?int $chat_id, ?int $number, array $statuses): Tournament {
		if ($number !== null) {
			$t = $this->tournaments->find($number);
			if ($t === null || !in_array($t->status, TournamentStatus::numbered(), true)) {
				throw new NotFound(sprintf('Турнира №%d сейчас нет. Список: /tournaments', $number));
			}
			return $this->number($t);
		}
		$candidates = $chat_id !== null
			? $this->tournaments->listByChat($chat_id, $statuses)
			: $this->tournaments->listByStatuses($statuses);
		if (count($candidates) === 1) {
			return $this->number($candidates[0]);
		}
		if ($candidates === []) {
			throw new NotFound('Подходящих турниров сейчас нет.');
		}
		$lines = array_map(function (Tournament $t): string {
			$this->number($t);
			return sprintf('№%d — %s, %s', (int) $t->number, $t->title, MoscowTime::format($t->starts_at));
		}, $candidates);
		throw new ValidationError("Уточни номер турнира:\n" . implode("\n", $lines));
	}
}
