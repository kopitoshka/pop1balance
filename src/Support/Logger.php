<?php

declare(strict_types=1);

namespace P1Bot\Support;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Daily log files in storage/logs, timestamps in Moscow time ("... MSK").
 * Levels: debug < info < error (LOG_LEVEL, default info). A null directory disables logging (tests).
 * If the directory is not writable, lines go to error_log() so they show up in the php-fpm log.
 */
final class Logger
{
    public const DEBUG = 'debug';
    public const INFO = 'info';
    public const ERROR = 'error';

    private const RANK = [self::DEBUG => 0, self::INFO => 1, self::ERROR => 2];

    private string $level;

    public function __construct(private ?string $dir = null, string $level = self::INFO)
    {
        $level = strtolower(trim($level));
        $this->level = isset(self::RANK[$level]) ? $level : self::INFO;
    }

    /** @param array<string, mixed> $config see config/config.php */
    public static function fromConfig(array $config): self
    {
        return new self((string) ($config['paths']['logs'] ?? ''), (string) ($config['log_level'] ?? self::INFO));
    }

    public static function null(): self
    {
        return new self(null);
    }

    public function level(): string
    {
        return $this->level;
    }

    public function isDebug(): bool
    {
        return $this->level === self::DEBUG;
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->write(self::DEBUG, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write(self::INFO, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write(self::ERROR, $message, $context);
    }

    public function exception(Throwable $e, string $where = ''): void
    {
        $this->error(($where !== '' ? $where . ': ' : '') . get_class($e) . ': ' . $e->getMessage(), [
            'at' => $e->getFile() . ':' . $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    /** Is the log directory usable? (for bin/diag.php) */
    public function isWritable(): bool
    {
        if ($this->dir === null) {
            return false;
        }
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        return is_dir($this->dir) && is_writable($this->dir);
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        if ($this->dir === null || self::RANK[$level] < self::RANK[$this->level]) {
            return;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow'));
        $line = sprintf(
            '[%s MSK] %s %s%s',
            $now->format('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        );
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        $file = $this->dir . '/bot-' . $now->format('Y-m-d') . '.log';
        if (@file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            error_log('[p1bot] log dir not writable (' . $this->dir . '): ' . $line);
        }
    }
}
