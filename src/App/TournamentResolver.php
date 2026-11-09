<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Domain\Exception\NotFound;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\MoscowTime;
use P1Bot\Repository\TournamentRepository;

/** Finds a tournament of the chat by "#5"/"5", or the only candidate when no id is given. */
final class TournamentResolver
{
    public function __construct(private TournamentRepository $tournaments)
    {
    }

    /** "#5" / "5" / "№5" => 5, otherwise null. */
    public static function parseId(string $token): ?int
    {
        return preg_match('/^[#№]?(\d{1,9})$/u', trim($token), $m) ? (int) $m[1] : null;
    }

    /**
     * @param list<string> $statuses candidates when $id is null
     */
    public function resolve(int $chatId, ?int $id, array $statuses): Tournament
    {
        if ($id !== null) {
            $t = $this->tournaments->find($id);
            if ($t === null || $t->chatId !== $chatId) {
                throw new NotFound(sprintf('Турнира №%d в этом чате нет.', $id));
            }
            return $t;
        }
        $candidates = $this->tournaments->listByChat($chatId, $statuses);
        if (count($candidates) === 1) {
            return $candidates[0];
        }
        if ($candidates === []) {
            throw new NotFound('Подходящих турниров сейчас нет.');
        }
        $lines = array_map(static function (Tournament $t): string {
            return sprintf('№%d — %s, %s', $t->id, $t->title, MoscowTime::format($t->startsAt));
        }, $candidates);
        throw new ValidationError("Уточни номер турнира:\n" . implode("\n", $lines));
    }
}
