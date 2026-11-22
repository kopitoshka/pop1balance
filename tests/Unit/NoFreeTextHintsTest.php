<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use P1Bot\App\CreateTournamentForm;
use P1Bot\Domain\TournamentService;
use P1Bot\View\Texts;
use PHPUnit\Framework\TestCase;

/**
	Help and hints point to buttons and forms, not to free text, /edit or /cancel.
*/
class NoFreeTextHintsTest extends TestCase {

	public function testTextsDoNotSuggestFreeTextOrRemovedCommands(): void {
		$texts = [
			Texts::help('pop1_bot', false),
			Texts::help('pop1_bot', true, true),
			Texts::groupHelp('pop1_bot'),
			Texts::groupHelp('pop1_bot', true, true, true),
			Texts::welcome('Андрей'),
			Texts::welcomeTournament('Андрей'),
			Texts::levels(),
			CreateTournamentForm::UNAVAILABLE_TEXT,
			CreateTournamentForm::EDIT_EXPIRED_TEXT,
			CreateTournamentForm::NOT_DRAFT_TEXT,
			TournamentService::LEVEL_LOCKED,
		];
		foreach ($texts as $text) {
			foreach (['обычным языком', 'просто напиши', 'просто пиши', 'Напиши мне', '/edit', '/cancel', '/levels', '/adduser'] as $needle) {
				self::assertStringNotContainsString($needle, $text);
			}
		}
	}
}
