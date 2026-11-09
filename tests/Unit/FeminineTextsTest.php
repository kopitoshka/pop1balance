<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
	The bot speaks about herself in the feminine gender: no masculine first-person forms in fixed texts.
*/
class FeminineTextsTest extends TestCase {
	protected const MASCULINE = [
		'Записал',
		'Опубликовал',
		'Обновил',
		'Не понял',
		'Снял тебя',
		'исправил сообщение',
		'Я рад',
		'Я готов',
		'Я понял',
		'не смог',
	];

	public function testNoMasculineFirstPersonInTexts(): void {
		$root = dirname(__DIR__, 2);
		$files = [$root . '/config/prompts/system.md'];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src')) as $file) {
			if ($file->isFile() && $file->getExtension() === 'php') {
				$files[] = $file->getPathname();
			}
		}
		foreach ($files as $path) {
			$text = (string) file_get_contents($path);
			foreach (self::MASCULINE as $form) {
				foreach (explode("\n", $text) as $i => $line) {
					// The prompt quotes the forms it forbids.
					$found = preg_match('/' . preg_quote($form, '/') . '(?![а-яё])/u', $line) === 1;
					if ($found && !str_contains($line, 'Никогда не используй мужской род')) {
						self::fail(sprintf('%s:%d uses «%s»', substr($path, strlen($root) + 1), $i + 1, trim($form)));
					}
				}
			}
		}
		self::assertTrue(true);
	}
}
