<?php

declare(strict_types=1);

namespace P1Bot\Support;

use Throwable;

/** Daily log files in storage/logs. A null directory disables logging (tests). */
final class Logger
{
    public function __construct(private ?string $dir = null)
    {
    }

    public static function null(): self
    {
        return new self(null);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    public function exception(Throwable $e, string $where = ''): void
    {
        $this->error(($where !== '' ? $where . ': ' : '') . get_class($e) . ': ' . $e->getMessage(), [
            'at' => $e->getFile() . ':' . $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        if ($this->dir === null) {
            return;
        }
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        $line = sprintf(
            "[%s] %s %s%s\n",
            gmdate('Y-m-d H:i:s'),
            $level,
            $message,
            $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
        @file_put_contents($this->dir . '/bot-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
