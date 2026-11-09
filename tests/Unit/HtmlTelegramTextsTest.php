<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use P1Bot\View\Html;
use P1Bot\View\Messages;
use P1Bot\View\Texts;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
	Every static HTML-mode text must only use Telegram-allowed tags.
	Catches raw «<ник>» / «<id>» placeholders that break parse_mode=HTML.
*/
class HtmlTelegramTextsTest extends TestCase {

	/**
		@return list<string>
	*/
	protected function staticHtmlSamples(): array {
		$samples = [
			Texts::greeting('pop1balance_bot'),
			Texts::groupHelp('pop1balance_bot'),
			Texts::help('pop1balance_bot', false, false),
			Texts::help('pop1balance_bot', true, false),
			Texts::help('pop1balance_bot', true, true),
			Texts::welcome('Андрей'),
			Texts::welcome(''),
			Texts::welcomeTournament('Андрей'),
			Texts::levels(false),
			Texts::levels(true),
			Texts::levelUsage('new'),
			Texts::levelUsage('edit', 1, 3),
			Texts::fallback('pop1balance_bot'),
			Texts::somethingWentWrong(),
			// /nick self-view (the production 400)
			'Ник пока не привязан. Поставить: <code>/nick игровой_ник</code>',
			"Твой ник: <b>dyumin</b>\nСменить: <code>/nick игровой_ник</code>"
				. "\nПоставить другому: /nick @user НикВИгре",
			'@vasya — ник <b>Mata</b>.',
		];
		$ref = new ReflectionClass(Messages::class);
		foreach ($ref->getConstants() as $value) {
			if (is_string($value)) {
				$samples[] = $value;
			} elseif (is_array($value)) {
				foreach ($value as $item) {
					if (is_string($item)) {
						$samples[] = $item;
					}
				}
			}
		}
		return $samples;
	}

	public function testStaticHelpAndPromptsOnlyUseAllowedTelegramTags(): void {
		foreach ($this->staticHtmlSamples() as $html) {
			$bad = Html::illegalTelegramTags($html);
			self::assertSame(
				[],
				$bad,
				'Illegal Telegram HTML tags ' . json_encode($bad, JSON_UNESCAPED_UNICODE)
					. ' in: ' . mb_substr($html, 0, 160),
			);
		}
	}

	public function testNickSelfViewRegressionDoesNotUseRawPlaceholderTags(): void {
		// Exact shape that failed in prod (byte offset ~53 on «<ник»).
		$broken = "Твой ник: <b>dyumin</b>\nСменить: /nick <ник в игре>";
		self::assertSame(['ник'], Html::illegalTelegramTags($broken));
		$fixed = "Твой ник: <b>dyumin</b>\nСменить: <code>/nick игровой_ник</code>";
		self::assertSame([], Html::illegalTelegramTags($fixed));
	}

	public function testSrcQuotedStringsHaveNoIllegalTelegramTags(): void {
		$root = dirname(__DIR__, 2) . '/src';
		$allowed_attr = '(?:\s[^>]*)?';
		$tag_re = '/<\/?([a-zA-Zа-яА-ЯёЁ][a-zA-Zа-яА-ЯёЁ0-9_-]*)' . $allowed_attr . '>/u';
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
			if (!$file->isFile() || $file->getExtension() !== 'php') {
				continue;
			}
			$path = $file->getPathname();
			$rel = substr($path, strlen(dirname($root)) + 1);
			$lines = file($path);
			if ($lines === false) {
				continue;
			}
			foreach ($lines as $i => $line) {
				$trim = ltrim($line);
				if ($trim === '' || str_starts_with($trim, '//') || str_starts_with($trim, '*')
					|| str_starts_with($trim, '/*') || str_starts_with($trim, '#')) {
					continue;
				}
				// Only look inside single- or double-quoted string fragments on the line.
				if (preg_match_all('/(?<!\\\\)([\'"])((?:\\\\.|(?!\1).)*)\1/u', $line, $chunks) < 1) {
					continue;
				}
				foreach ($chunks[2] as $chunk) {
					if (!str_contains($chunk, '<')) {
						continue;
					}
					if (preg_match_all($tag_re, $chunk, $m) < 1) {
						continue;
					}
					foreach ($m[1] as $raw) {
						$name = mb_strtolower($raw);
						if (!isset(Html::TELEGRAM_TAGS[$name])) {
							self::fail(sprintf(
								'%s:%d has illegal Telegram HTML tag «%s» in %s',
								$rel,
								$i + 1,
								$raw,
								mb_substr($chunk, 0, 120),
							));
						}
					}
				}
			}
		}
		self::assertTrue(true);
	}
}
