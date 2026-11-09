<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Ai\Tools;
use P1Bot\Domain\CreateTournament;
use P1Bot\Domain\EditTournament;
use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Tests\Support\IntegrationTestCase;

/**
	Temporary product decision: rating scales are hidden from users,
	only the owner changes player levels and a tournament's scale.
*/
class ScaleHiddenTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		// Telegram reports admin2 as a chat admin (the sync would otherwise drop him).
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
		foreach (['шкал', 'баланс', '/balance', '/level ', 'главного админа'] as $hidden) {
			self::assertStringNotContainsStringIgnoringCase($hidden, $help);
		}

		$this->dispatch($this->privateMessage($this->owner, '/help'));
		$owner_help = $this->http->lastText();
		self::assertStringContainsString('Только для главного админа', $owner_help);
		self::assertStringContainsString('/level @user 4', $owner_help);
		self::assertStringContainsString('/balance', $owner_help);
		self::assertStringNotContainsStringIgnoringCase('шкал', $owner_help);
	}

	public function testOnlyOwnerSetsLevels(): void {
		$vasya = $this->player('vasya');
		$this->dispatch($this->privateMessage($this->admin, '/level @vasya 5'));
		self::assertSame('Уровни игроков меняет только главный админ.', $this->http->lastText());
		$this->dispatch($this->privateMessage($vasya, '/level @vasya 6'));
		self::assertSame('Уровни игроков меняет только главный админ.', $this->http->lastText());
		self::assertSame(3, $this->app->balances->levelOf($this->app->balances->findDefault()->id, $vasya->id));

		$this->dispatch($this->privateMessage($this->admin, '/balance'));
		self::assertSame('Это доступно только главному админу.', $this->http->lastText());

		$this->dispatch($this->privateMessage($this->owner, '/level @vasya 5'));
		self::assertSame('Записал: @vasya — высокий (5).', $this->http->lastText());
		self::assertSame(5, $this->app->balances->levelOf($this->app->balances->findDefault()->id, $vasya->id));
	}

	public function testOnlyOwnerChangesTournamentScale(): void {
		$other = $this->app->balances->create(self::CHAT_ID, $this->owner->id, 'Другая');
		$this->dispatch($this->privateMessage($this->admin, '/new 23.11 20:00 | Кубок | 3 | 10'));
		$default_id = $this->app->balances->findDefault()->id;

		$this->dispatch($this->privateMessage($this->admin, '/edit 1 баланс ' . $other->id));
		self::assertSame('Это доступно только главному админу.', $this->http->lastText());
		self::assertSame($default_id, $this->app->tournament_repo->find(1)->balance_id);

		$this->dispatch($this->privateMessage($this->admin, '/edit 1 уровень 11'));
		self::assertSame($default_id, $this->app->tournament_repo->find(1)->balance_id, 'other edits keep the scale');

		try {
			$this->app->tournaments->create(new CreateTournament(
				self::CHAT_ID,
				$this->admin->id,
				'X',
				'2026-11-24 19:00',
				3,
				'10',
				$other->id,
			));
			self::fail('admin must not pick a scale');
		} catch (PermissionDenied $e) {
			self::assertSame('Это доступно только главному админу.', $e->getMessage());
		}

		$this->dispatch($this->privateMessage($this->owner, '/edit 1 баланс ' . $other->id));
		self::assertSame($other->id, $this->app->tournament_repo->find(1)->balance_id);
		self::assertStringNotContainsStringIgnoringCase('шкал', $this->http->lastText(), 'the card stays scale-free');

		$t = $this->app->tournaments->edit(
			$this->owner->id,
			1,
			new EditTournament(null, null, null, null, $default_id),
		);
		self::assertSame($default_id, $t->balance_id);
	}

	public function testAiToolsAndPromptHideScales(): void {
		foreach ([[false, false], [true, false]] as [$is_admin, $is_owner]) {
			$json = (string) json_encode(Tools::definitions($is_admin, $is_owner), JSON_UNESCAPED_UNICODE);
			self::assertStringNotContainsStringIgnoringCase('шкал', $json);
			self::assertStringNotContainsString('balance', $json);
			self::assertNotContains('set_level', Tools::names($is_admin, $is_owner));
		}
		$owner_tools = Tools::names(true, true);
		foreach (['set_level', 'create_balance', 'show_balance', 'create_tournament'] as $name) {
			self::assertContains($name, $owner_tools);
		}
		self::assertStringContainsString('balance_id', (string) json_encode(Tools::definitions(true, true)));

		$prompt = (string) file_get_contents(dirname(__DIR__, 2) . '/config/prompts/system.md');
		self::assertStringNotContainsStringIgnoringCase('шкал', $prompt);
		self::assertStringNotContainsStringIgnoringCase('баланс', $prompt);
	}

	public function testAiSetLevelIsRefusedForAdminsAndBalanceIdIgnored(): void {
		$vasya = $this->player('vasya');
		$other = $this->app->balances->create(self::CHAT_ID, $this->owner->id, 'Другая');
		$this->http->queueOpenAi($this->call('r1', 'set_level', ['player' => '@vasya', 'level' => '5']));
		$this->http->queueOpenAi($this->text('r2', 'Уровни игроков меняет только главный админ.'));
		$this->dispatch($this->privateMessage($this->admin, '@' . self::BOT_USERNAME . ' поставь @vasya уровень 5'));
		self::assertSame(3, $this->app->balances->levelOf($this->app->balances->findDefault()->id, $vasya->id));
		self::assertStringContainsString(
			'"ok":false',
			$this->lastToolOutput(),
			'set_level is not even offered to admins',
		);

		$this->http->queueOpenAi($this->call('r3', 'create_tournament', [
			'title' => 'Кубок',
			'starts_at_msk' => '23.11 20:00',
			'team_size' => 3,
			'level' => '10',
			'balance_id' => $other->id,
		]));
		$this->http->queueOpenAi($this->text('r4', 'Готово'));
		$this->dispatch($this->privateMessage($this->admin, '@' . self::BOT_USERNAME . ' создай кубок'));
		self::assertSame($this->app->balances->findDefault()->id, $this->app->tournament_repo->find(1)->balance_id);
		self::assertStringNotContainsStringIgnoringCase('шкал', $this->http->sent('sendMessage')[1]['text'] ?? '');
	}

	protected function lastToolOutput(): string {
		$requests = array_values(array_filter($this->http->requests, static function (array $r): bool {
			return str_ends_with($r['url'], '/responses');
		}));
		$last = end($requests)['json'];
		foreach ($last['input'] as $item) {
			if (($item['type'] ?? '') === 'function_call_output') {
				return (string) $item['output'];
			}
		}
		return '';
	}

	protected function call(string $id, string $name, array $args): array {
		return ['id' => $id, 'output' => [[
			'type' => 'function_call',
			'id' => 'fc_' . $id,
			'call_id' => 'c_' . $id,
			'name' => $name,
			'arguments' => json_encode($args, JSON_UNESCAPED_UNICODE),
		]]];
	}

	protected function text(string $id, string $text): array {
		return ['id' => $id, 'output' => [[
			'type' => 'message',
			'role' => 'assistant',
			'content' => [['type' => 'output_text', 'text' => $text]],
		]]];
	}
}
