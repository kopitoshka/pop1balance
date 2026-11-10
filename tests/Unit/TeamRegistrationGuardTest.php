<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use P1Bot\App\TeamRegistrationGuard;

class TeamRegistrationGuardTest extends TestCase {

	public const MODEL_ASK = 'Для команды «секта» нужны Telegram-ники Ксюши и Стаса в формате @username. «tired angel» и «stas66» — это их ники в игре или Telegram-ники?';

	public function testDetectsExactModelAsk(): void {
		self::assertTrue(TeamRegistrationGuard::isUsernameAsk(self::MODEL_ASK));
		self::assertFalse(TeamRegistrationGuard::isUsernameAsk('Записала команду «секта»: ты, @just_tired_angel и @rustas66.'));
	}

	public function testDetectsTeamRegistrationIntent(): void {
		self::assertTrue(TeamRegistrationGuard::isTeamRegistrationIntent(
			'запиши команду «секта» с Ксюшей и Стасом, ники tired angel и stas66',
		));
		self::assertTrue(TeamRegistrationGuard::isTeamRegistrationIntent('Зарегистрируй нас командой секта'));
		self::assertFalse(TeamRegistrationGuard::isTeamRegistrationIntent('какой у меня уровень?'));
	}

	public function testParsesQuotedTeamAndPrefersNicks(): void {
		$parsed = TeamRegistrationGuard::parse(
			'запиши команду «секта» с Ксюшей и Стасом, ники tired angel и stas66',
		);
		self::assertNotNull($parsed);
		self::assertSame('секта', $parsed['team_name']);
		self::assertContains('tired angel', $parsed['players']);
		self::assertContains('stas66', $parsed['players']);
		self::assertNotContains('Ксюшей', $parsed['players']);
		self::assertNotContains('Стасом', $parsed['players']);
	}

	public function testParsesParentheticalNicks(): void {
		$parsed = TeamRegistrationGuard::parse(
			'Запиши нас командой секта: Ксюша (tired angel) и Стас (stas66)',
		);
		self::assertNotNull($parsed);
		self::assertSame('секта', $parsed['team_name']);
		self::assertContains('tired angel', $parsed['players']);
		self::assertContains('stas66', $parsed['players']);
	}

	public function testParsesFirstNamesWhenNoNicks(): void {
		$parsed = TeamRegistrationGuard::parse('запиши команду «секта» с Ксюшей и Стасом');
		self::assertNotNull($parsed);
		self::assertSame('секта', $parsed['team_name']);
		self::assertContains('Ксюшей', $parsed['players']);
		self::assertContains('Стасом', $parsed['players']);
	}

	public function testPromptForbidsAskingForUsernames(): void {
		$prompt = (string) file_get_contents(dirname(__DIR__, 2) . '/config/prompts/system.md');
		self::assertStringNotContainsString('Игроков указывай как @username', $prompt);
		self::assertStringContainsString('Никогда не спрашивай @username', $prompt);
		self::assertStringContainsString('сразу вызывай register_team', $prompt);
	}
}
