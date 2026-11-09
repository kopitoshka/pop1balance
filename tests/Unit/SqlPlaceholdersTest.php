<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use P1Bot\Support\Database;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

/**
	MySQL native prepares reject reused named placeholders (HY093). SQLite does not —
	so we statically scan repository/app SQL and assert uniqueness.
*/
class SqlPlaceholdersTest extends TestCase {

	public function testAssertRejectsDuplicateNamedPlaceholder(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Duplicate named placeholder :id');
		Database::assertUniqueNamedPlaceholders('SELECT * FROM t WHERE a = :id OR b = :id');
	}

	public function testAssertAllowsUniquePlaceholders(): void {
		Database::assertUniqueNamedPlaceholders('SELECT * FROM t WHERE a = :a AND b = :b');
		self::assertSame(['a', 'b'], Database::namedPlaceholders('SELECT * FROM t WHERE a = :a AND b = :b'));
	}

	public function testNoDuplicateNamedPlaceholdersInSourceSql(): void {
		$roots = [
			dirname(__DIR__, 2) . '/src/Repository',
			dirname(__DIR__, 2) . '/src/App',
			dirname(__DIR__, 2) . '/src/Domain',
			dirname(__DIR__, 2) . '/src/Support',
		];
		$failures = [];
		foreach ($roots as $root) {
			if (!is_dir($root)) {
				continue;
			}
			$iterator = new RegexIterator(
				new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)),
				'/\.php$/',
			);
			foreach ($iterator as $file) {
				$path = (string) $file;
				$text = (string) file_get_contents($path);
				foreach ($this->sqlStrings($text) as [$line, $sql]) {
					$names = Database::namedPlaceholders($sql);
					$seen = [];
					foreach ($names as $name) {
						if (isset($seen[$name])) {
							$rel = substr($path, strlen(dirname(__DIR__, 2)) + 1);
							$failures[] = sprintf('%s:%d duplicate :%s in %s', $rel, $line, $name, $this->short($sql));
							break;
						}
						$seen[$name] = true;
					}
				}
				// Built SQL that implode()s the same placeholder list twice (UNION IN (...)).
				if (preg_match_all(
					'/\$sql\s*=\s*(.+?);/s',
					$text,
					$assigns,
					PREG_OFFSET_CAPTURE,
				)) {
					foreach ($assigns[1] as [$expr, $offset]) {
						$implodes = preg_match_all("/implode\\(\\s*','\\s*,\\s*\\\$keys\\s*\\)/", $expr);
						if ($implodes >= 2) {
							$line = substr_count(substr($text, 0, $offset), "\n") + 1;
							$rel = substr($path, strlen(dirname(__DIR__, 2)) + 1);
							$failures[] = sprintf(
								'%s:%d reuses \$keys implode %d times in one SQL (duplicate named params)',
								$rel,
								$line,
								$implodes,
							);
						}
					}
				}
			}
		}
		self::assertSame([], $failures, "Duplicate named placeholders:\n" . implode("\n", $failures));
	}

	/**
		@return list<array{0: int, 1: string}>
	*/
	protected function sqlStrings(string $text): array {
		$out = [];
		if (!preg_match_all(
			'/->(?:fetchAll|fetchOne|fetchValue|run|insert)\\(\\s*((?:\'[^\']*\'|"[^"]*"|\\.|[\\s])+?)(?:,|\\))/s',
			$text,
			$matches,
			PREG_OFFSET_CAPTURE,
		)) {
			return $out;
		}
		foreach ($matches[1] as [$chunk, $offset]) {
			$parts = [];
			if (preg_match_all('/\'([^\']*)\'|"([^"]*)"/', $chunk, $bits)) {
				foreach ($bits[0] as $i => $_) {
					$parts[] = $bits[1][$i] !== '' ? $bits[1][$i] : $bits[2][$i];
				}
			}
			$sql = implode('', $parts);
			if ($sql === '' || !str_contains($sql, ':')) {
				continue;
			}
			if (!preg_match('/\\b(SELECT|INSERT|UPDATE|DELETE)\\b/i', $sql)) {
				continue;
			}
			$line = substr_count(substr($text, 0, $offset), "\n") + 1;
			$out[] = [$line, $sql];
		}
		return $out;
	}

	protected function short(string $sql): string {
		$one = preg_replace('/\\s+/', ' ', $sql) ?? $sql;
		return mb_strlen($one) > 120 ? mb_substr($one, 0, 120) . '…' : $one;
	}
}
