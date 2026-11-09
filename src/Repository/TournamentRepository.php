<?php

declare(strict_types=1);

namespace P1Bot\Repository;

use DateTimeImmutable;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Support\Database;
use P1Bot\Support\DateFormat;

final class TournamentRepository
{
    /** Columns that update() may touch. */
    private const UPDATABLE = [
        'title', 'starts_at', 'team_size', 'balance_id', 'reference_team', 'max_points',
        'max_players', 'status', 'announce_msg_id', 'reminded_at', 'card_msg_id', 'card_shown_to',
    ];

    public function __construct(private Database $db)
    {
    }

    public function find(int $id): ?Tournament
    {
        $row = $this->db->fetchOne('SELECT * FROM tournaments WHERE id = :id', ['id' => $id]);
        return $row === null ? null : Tournament::fromRow($row);
    }

    /**
     * @param array{chat_id: int, organizer_id: int, title: string, starts_at: DateTimeImmutable, team_size: int,
     *     balance_id: ?int, reference_team: ?string, max_points: ?int, max_players: int, status: string} $data
     */
    public function create(array $data, DateTimeImmutable $now): Tournament
    {
        $id = $this->db->insert(
            'INSERT INTO tournaments
                (chat_id, organizer_id, title, starts_at, team_size, balance_id, reference_team, max_points, max_players, status, created_at)
             VALUES (:chat, :org, :title, :starts, :size, :bal, :ref, :maxp, :maxpl, :status, :created)',
            [
                'chat' => $data['chat_id'],
                'org' => $data['organizer_id'],
                'title' => $data['title'],
                'starts' => DateFormat::toDb($data['starts_at']),
                'size' => $data['team_size'],
                'bal' => $data['balance_id'],
                'ref' => $data['reference_team'],
                'maxp' => $data['max_points'],
                'maxpl' => $data['max_players'],
                'status' => TournamentStatus::assertValid($data['status']),
                'created' => DateFormat::toDb($now),
            ],
        );
        return $this->find($id) ?? throw new \LogicException('Tournament insert failed');
    }

    /** @param array<string, scalar|null|DateTimeImmutable> $fields */
    public function update(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $sets = [];
        $params = ['id' => $id];
        foreach ($fields as $column => $value) {
            if (!in_array($column, self::UPDATABLE, true)) {
                throw new \InvalidArgumentException("Column $column is not updatable");
            }
            $sets[] = "$column = :$column";
            $params[$column] = match (true) {
                $value instanceof DateTimeImmutable => DateFormat::toDb($value),
                default => $value,
            };
        }
        $this->db->run('UPDATE tournaments SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
    }

    /** Draft whose confirmation card is the given message. */
    public function findByCardMessage(int $chatId, int $messageId): ?Tournament
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM tournaments WHERE chat_id = :c AND card_msg_id = :m',
            ['c' => $chatId, 'm' => $messageId],
        );
        return $row === null ? null : Tournament::fromRow($row);
    }

    /**
     * @param list<string> $statuses
     * @return list<Tournament>
     */
    public function listByChat(int $chatId, array $statuses): array
    {
        if ($statuses === []) {
            return [];
        }
        $params = ['chat' => $chatId];
        $placeholders = [];
        foreach (array_values($statuses) as $i => $status) {
            $placeholders[] = ":s$i";
            $params["s$i"] = TournamentStatus::assertValid($status);
        }
        $rows = $this->db->fetchAll(
            'SELECT * FROM tournaments WHERE chat_id = :chat AND status IN (' . implode(', ', $placeholders) . ')
             ORDER BY starts_at, id',
            $params,
        );
        return array_map([Tournament::class, 'fromRow'], $rows);
    }

    /**
     * Tournaments with one of $statuses whose start is within [$from, $to].
     *
     * @param list<string> $statuses
     * @return list<Tournament>
     */
    public function listStartingBetween(array $statuses, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $params = ['from' => DateFormat::toDb($from), 'to' => DateFormat::toDb($to)];
        $placeholders = [];
        foreach (array_values($statuses) as $i => $status) {
            $placeholders[] = ":s$i";
            $params["s$i"] = TournamentStatus::assertValid($status);
        }
        $rows = $this->db->fetchAll(
            'SELECT * FROM tournaments WHERE status IN (' . implode(', ', $placeholders) . ')
               AND starts_at >= :from AND starts_at <= :to ORDER BY starts_at, id',
            $params,
        );
        return array_map([Tournament::class, 'fromRow'], $rows);
    }
}
