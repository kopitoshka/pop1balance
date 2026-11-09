<?php

declare(strict_types=1);

namespace P1Bot\Tests\Support;

use P1Bot\Support\Logger;

/** A real Logger writing into a temp dir, with helpers to read the lines back. */
final class LogCapture
{
    public string $dir;
    public Logger $logger;

    public function __construct(string $level = 'info')
    {
        $this->dir = sys_get_temp_dir() . '/p1bot-logs-' . bin2hex(random_bytes(4));
        $this->logger = new Logger($this->dir, $level);
    }

    public function text(): string
    {
        $out = '';
        foreach (glob($this->dir . '/bot-*.log') ?: [] as $file) {
            $out .= (string) file_get_contents($file);
        }
        return $out;
    }

    /** @return list<string> */
    public function lines(): array
    {
        return array_values(array_filter(explode("\n", $this->text())));
    }

    public function clear(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function __destruct()
    {
        $this->clear();
        @rmdir($this->dir);
    }
}
