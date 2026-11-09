<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use P1Bot\Support\Logger;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/p1bot-logtest-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function contents(): string
    {
        $files = glob($this->dir . '/bot-*.log') ?: [];
        return $files === [] ? '' : (string) file_get_contents($files[0]);
    }

    public function testInfoLevelSkipsDebugAndUsesMoscowTime(): void
    {
        $logger = new Logger($this->dir);
        $logger->debug('hidden');
        $logger->info('visible', ['a' => 'б']);
        $logger->error('bad');

        $log = $this->contents();
        self::assertStringNotContainsString('hidden', $log);
        self::assertMatchesRegularExpression('/^\[\d{4}-\d\d-\d\d \d\d:\d\d:\d\d MSK\] INFO visible \{"a":"б"\}$/m', $log);
        self::assertStringContainsString('ERROR bad', $log);
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d');
        self::assertFileExists($this->dir . '/bot-' . $today . '.log');
    }

    public function testLevels(): void
    {
        $logger = new Logger($this->dir, 'DEBUG');
        self::assertTrue($logger->isDebug());
        $logger->debug('raw');
        self::assertStringContainsString('DEBUG raw', $this->contents());

        $quiet = new Logger($this->dir, 'error');
        $quiet->info('not written');
        self::assertStringNotContainsString('not written', $this->contents());

        self::assertSame('info', (new Logger($this->dir, 'nonsense'))->level());
        self::assertSame('error', Logger::fromConfig(['paths' => ['logs' => $this->dir], 'log_level' => 'error'])->level());
        self::assertSame('info', Logger::fromConfig(['paths' => ['logs' => $this->dir]])->level());
    }

    public function testFallsBackToErrorLogWhenDirIsNotWritable(): void
    {
        $blocker = tempnam(sys_get_temp_dir(), 'p1bot-file');
        $sink = tempnam(sys_get_temp_dir(), 'p1bot-errlog');
        $previous = ini_set('error_log', (string) $sink);
        try {
            $logger = new Logger($blocker . '/logs'); // a directory under a regular file can never exist
            self::assertFalse($logger->isWritable());
            $logger->error('still visible');
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }
        $out = (string) file_get_contents((string) $sink);
        @unlink($blocker);
        @unlink((string) $sink);
        self::assertStringContainsString('[p1bot]', $out);
        self::assertStringContainsString('ERROR still visible', $out);
    }

    public function testNullLoggerWritesNothing(): void
    {
        Logger::null()->error('x');
        self::assertFalse(Logger::null()->isWritable());
        self::assertDirectoryDoesNotExist($this->dir);
    }
}
