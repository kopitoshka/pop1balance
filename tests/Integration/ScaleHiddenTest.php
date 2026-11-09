<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Ai\Tools;
use P1Bot\Domain\CreateTournament;
use P1Bot\Domain\EditTournament;
use P1Bot\Tests\Support\IntegrationTestCase;

/**
	Single global rating scale: no per-tournament balance pick, no /balance.
*/
class ScaleHiddenTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->http->onTelegram('getChatAdministrators', function (): array {
			return ['ok' => true, 'result' => [[
				'status' => 'administrator',
				'user' => [
					'id' => $this->tgIdOf($this->admin),
					'is_bot' => false,
					'first_name' => 'Admin',
					'username' => 'admin2',
				],
			]]];
		});
	}

	protected function config(): array {
		$config = parent::config();
		$config['openai']['api_key'] = 'sk-test';
		return $config;
	}

	public function testCardDoesNotShowTheScale(): void {
		$this->dispatch($this->privateMessage($this->admin, '/new 23.11 20:00 | Кубок | 3 | 10'));

		$card = $this->http->lastText();
		self::assertStringContainsString('Уровень турнира', $card);
		self::assertStringNotContainsStringIgnoringCase('шкал', $card);
		self::assertStringNotContainsStringIgnoringCase('баланс', $card);
		self::assertSame(
			$this->app->balances->findDefault()->id,
			$this->app->tournament_repo->find(1)->balance_id,
			'admins always get the default scale',
		);
	}

	public function testPublicHelpHasNoScaleAndOwnerSeesHisCommands(): void {
		$this->dispatch($this->privateMessage($this->admin, '/help'));
		$help = $this->http->lastText();
		self::assertStringContainsString('/new', $help);
		self::assertStringContainsString('/edit <code>номер</code>', $help);
		self::assertStringContainsString('/cancel <code>номер</code>', $help);
		self::assertStringNotContainsString('/close', $help);
		self::assertStringNotContainsString('/start —', $help);
		foreach (['шкал', 'баланс', '/balance', '/level @user 4', 'главного админа'] as $hidden) {
			self::assertStringNotContainsStringIgnoringCase($hidden, $help);
		}

		$this->dispatch($this->privateMessage($this->owner, '/help'));
		$owner_help = $this->http->lastText();
		self::assertStringContainsString('Только для главного админа', $owner_help);
		self::assertStringContainsString('/level @user 4', $owner_help);
		self::assertStringNotContainsString('/balance', $owner_help);
		self::assertStringNotContainsStringIgnoringCase('шкал', $owner_help);
		self::assertStringContainsString('/team', $owner_help);
		self::assertStringContainsString('<code>/nick игровой_ник</code>', $owner_help);
	}

	public function testAnyoneCanViewOwnAndOthersLevel(): void {
		$vasya = $this->player('vasya');
		$this->dispatch($this->privateMessage($this->owner, '/level @vasya 5'));
		$this->http->reset();
		$this->dispatch($this->privateMessage($vasya, '/level'));
		self::assertStringContainsString('Твой уровень: высокий (5)', $this->http->lastText());
		self::assertStringContainsString('Все уровни — /levels', $this->http->lastText());
		self::assertStringNotContainsString('Поставить:', $this->http->lastText());
		$this->dispatch($this->privateMessage($this->admin, '/level @vasya'));
		self::assertStringContainsString('@vasya — высокий (5)', $this->http->lastText());
	}

	public function testOnlyOwnerSetsLevels(): void {
		$vasya = $this->player('vasya');
		$this->dispatch($this->privateMessage($this->admin, '/level @vasya 5'));
		self::assertSame('Уровни игроков меняет только главный админ.', $this->http->lastText());
		$this->dispatch($this->privateMessage($vasya, '/level @vasya 6'));
		self::assertSame('Уровни игроков меняет только главный админ.', $this->http->lastText());
		self::assertSame(3, $this->app->balances->levelOf($this->app->balances->findDefault()->id, $vasya->id));

		$this->dispatch($this->privateMessage($this->admin, '/balance'));
		self::assertStringContainsString('не знаю', mb_strtolower($this->http->lastText()));

		$this->dispatch($this->privateMessage($this->owner, '/level @vasya 5'));
		self::assertSame('Записала: @vasya — высокий (5).', $this->http->lastText());
		self::assertSame(5, $this->app->balances->levelOf($this->app->balances->findDefault()->id, $vasya->id));
	}

	public function testEditBalanceFieldGoneAndCreateIgnoresScaleId(): void {
		$other = $this->app->balances->create(self::CHAT_ID, $this->owner->id, 'Другая');
		$this->dispatch($this->privateMessage($this->admin, '/new 23.11 20:00 | Кубок | 3 | 10'));
		$default_id = $this->app->balances->findDefault()->id;

		$this->dispatch($this->privateMessage($this->owner, '/edit 1 баланс ' . $other->id));
		self::assertStringContainsString('Не знаю поле', $this->http->lastText());
		self::assertSame($default_id, $this->app->tournament_repo->find(1)->balance_id);

		$t = $this->app->tournaments->create(new CreateTournament(
			self::CHAT_ID,
			$this->admin->id,
			'X',
			'2026-11-24 19:00',
			3,
			'10',
			$other->id,
		));
		self::assertSame($default_id, $t->balance_id, 'non-default scale id ignored');

		$t = $this->app->tournaments->edit(
			$this->owner->id,
			1,
			new EditTournament(null, null, null, null, $other->id),
		);
		self::assertSame($default_id, $t->balance_id, 'edit balance_id is a no-op');
	}

	public function testAiToolsHaveNoScaleOrClose(): void {
		foreach ([[false, false], [true, false], [true, true]] as [$is_admin, $is_owner]) {
			$json = (string) json_encode(Tools::definitions($is_admin, $is_owner), JSON_UNESCAPED_UNICODE);
			self::assertStringNotContainsStringIgnoringCase('шкал', $json);
			self::assertStringNotContainsString('balance', $json);
			self::assertStringNotContainsString('close_registration', $json);
			self::assertStringNotContainsString('create_balance', $json);
			self::assertStringNotContainsString('show_balance', $json);
		}
		$owner_tools = Tools::names(true, true);
		foreach (['set_level', 'assign_nick', 'create_tournament', 'set_nick'] as $name) {
			self::assertContains($name, $owner_tools);
		}
		self::assertContains('set_nick', Tools::names(false, false));

		$prompt = (string) file_get_contents(dirname(__DIR__, 2) . '/config/prompts/system.md');
		self::assertStringNotContainsStringIgnoringCase('шкал', $prompt);
		self::assertStringNotContainsStringIgnoringCase('баланс', $prompt);
	}

	public function testOwnerLevelsListsPlayersThenHelp(): void {
		$vasya = $this->player('vasya');
		$this->rate($vasya, 5);
		$this->dispatch($this->privateMessage($this->owner, '/levels'));
		$text = $this->http->lastText();
		// may be split — check all messages
		$all = implode("\n", array_column($this->http->sent('sendMessage'), 'text'));
		self::assertStringContainsString('Уровни игроков', $all);
		self::assertStringContainsString('высокий', $all);
		self::assertStringContainsString('@vasya', $all);
		self::assertStringContainsString('Уровень турнира', $all);
	}
}
