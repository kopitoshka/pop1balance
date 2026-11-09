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
 * Short tournament numbers and lookup.
 *
 * Users never see real ids: a tournament's number is its position (1, 2, 3, ...) among the chat's
 * not finished/cancelled tournaments (drafts included), ordered by start time, then id.
 * Numbers can shift when an earlier tournament finishes, is cancelled or a new one starts earlier;
 * announcements are re-rendered then (Announcer::renumber).
 */
final class TournamentResolver
{
    public function __construct(private TournamentRepository $tournaments)
    {
    }

    /** @return array<int, int> real id => short number */
    public function numbers(int $chatId): array
    {
        $numbers = [];
        foreach ($this->tournaments->listByChat($chatId, TournamentStatus::numbered()) as $i => $t) {
            $numbers[$t->id] = $i + 1;
        }
        return $numbers;
    }

    /** Sets $t->number (null for finished/cancelled) and returns the same object. */
    public function number(Tournament $t): Tournament
    {
        $t->number = $this->numbers($t->chatId)[$t->id] ?? null;
        return $t;
    }

    /** "#5" / "5" / "№5" => 5, otherwise null. */
    public static function parseId(string $token): ?int
    {
        return preg_match('/^[#№]?(\d{1,9})$/u', trim($token), $m) ? (int) $m[1] : null;
    }

    /**
     * @param list<string> $statuses candidates when $id is null
     */
    public function resolve(int $chatId, ?int $number, array $statuses): Tournament
    {
        if ($number !== null) {
            $realId = array_search($number, $this->numbers($chatId), true);
            $t = $realId !== false ? $this->tournaments->find((int) $realId) : null;
            if ($t === null) {
                throw new NotFound(sprintf('Турнира №%d сейчас нет. Список: /tournaments', $number));
            }
            return $this->number($t);
        }
        $candidates = $this->tournaments->listByChat($chatId, $statuses);
        if (count($candidates) === 1) {
            return $this->number($candidates[0]);
        }
        if ($candidates === []) {
            throw new NotFound('Подходящих турниров сейчас нет.');
        }
        $numbers = $this->numbers($chatId);
        $lines = array_map(static function (Tournament $t) use ($numbers): string {
            return sprintf('№%d — %s, %s', $numbers[$t->id] ?? 0, $t->title, MoscowTime::format($t->startsAt));
        }, $candidates);
        throw new ValidationError("Уточни номер турнира:\n" . implode("\n", $lines));
    }
}
