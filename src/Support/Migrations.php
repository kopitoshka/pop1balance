<?php

declare(strict_types=1);

namespace P1Bot\Support;

use PDO;
use Throwable;

/** Read-only view of migrations/*.sql vs. the schema_migrations table (for bin/diag.php). */
final class Migrations
{
    public function __construct(private PDO $pdo, private string $dir)
    {
    }

    /** @return list<string> all migration file names, sorted */
    public function all(): array
    {
        $files = glob(rtrim($this->dir, '/') . '/*.sql') ?: [];
        $names = array_map('basename', $files);
        sort($names);
        return array_values($names);
    }

    /**
     * Not yet applied migrations. Mirrors bin/migrate.php: without schema_migrations but with
     * an existing `players` table, 001_init.sql counts as applied.
     *
     * @return list<string>
     */
    public function pending(): array
    {
        $applied = $this->applied();
        if ($applied === null) {
            $applied = $this->tableExists('players') ? ['001_init.sql'] : [];
        }
        return array_values(array_diff($this->all(), $applied));
    }

    public function hasTrackingTable(): bool
    {
        return $this->applied() !== null;
    }

    /** @return list<string>|null null when schema_migrations does not exist */
    private function applied(): ?array
    {
        try {
            $stmt = $this->pdo->query('SELECT name FROM schema_migrations');
            return $stmt === false ? null : array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return null;
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            return $this->pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1') !== false;
        } catch (Throwable $e) {
            return false;
        }
    }
}
