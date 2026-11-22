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
		$this->newDraft($this->admin, '23.11 20:00 | Кубок | 3 | 10');

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
		self::assertStringContainsString('/create_tournament', $help);
		self::assertStringNotContainsString('/new', $help);
		self::assertStringNotContainsString('/edit', $help);
		self::assertStringNotContainsString('/cancel', $help);
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
		self::assertStringContainsString('Как устроены уровни — /help_level', $this->http->lastText());
		self::assertStringNotContainsString('Поставить:', $this->http->lastText());
		$this->dispatch($this->privateMessage($this->admin, '/level @vasya'));
		self::assertStringContainsString('@vasya — высокий (5)', $this->http->lastText());
	}

	public function testOnlyOwnerSetsLevels(): void {
		$vasya = $this->player('vasya');
		$this->dispatch($this->privateMessage($this->admin, '/level @vasya 5'));
		self::assertSame('Уровни игроков меняет только главный админ.', $this->http->lastText());
		$this->dispatch($this->privateMessage($vasya, '/level @vasya 6'));
		self::assertSame('Уровень ставит админ.', $this->http->lastText());
		$this->dispatch($this->privateMessage($vasya, '/level 4'));
		self::assertSame('Уровень ставит админ.', $this->http->lastText());
		$this->dispatch($this->privateMessage($vasya, '/level высокий'));
		self::assertSame('Уровень ставит админ.', $this->http->lastText());
		$this->dispatch($this->privateMessage($vasya, '/level себя 4'));
		self::assertSame('Уровень ставит админ.', $this->http->lastText());
		self::assertSame(3, $this->app->balances->levelOf($this->app->balances->findDefault()->id, $vasya->id));

		$this->dispatch($this->privateMessage($this->admin, '/balance'));
		self::assertStringContainsString('не знаю', mb_strtolower($this->http->lastText()));

		$this->dispatch($this->privateMessage($this->owner, '/level @vasya 5'));
		self::assertSame('Записала: @vasya — высокий (5).', $this->http->lastText());
		self::assertSame(5, $this->app->balances->levelOf($this->app->balances->findDefault()->id, $vasya->id));

		$this->dispatch($this->privateMessage($this->owner, '/level 4'));
		self::assertSame('Записала: ' . $this->owner->plainLabel() . ' — средний+ (4).', $this->http->lastText());
		self::assertSame(4, $this->app->balances->levelOf($this->app->balances->findDefault()->id, $this->owner->id));
	}

	public function testEditBalanceFieldGoneAndCreateIgnoresScaleId(): void {
		$other = $this->app->balances->create(self::CHAT_ID, $this->owner->id, 'Другая');
		$this->newDraft($this->admin, '23.11 20:00 | Кубок | 3 | 10');
		$default_id = $this->app->balances->findDefault()->id;

		$this->dispatch($this->privateMessage($this->owner, '/edit 1 баланс ' . $other->id));
		self::assertStringContainsString('Такой команды я не знаю', $this->http->lastText());
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
		self::assertContains('list_players', Tools::names(false, false));
		self::assertSame(Tools::names(false, false), Tools::names(true, true), 'the same info tools for everyone');

		$prompt = (string) file_get_contents(dirname(__DIR__, 2) . '/config/prompts/system.md');
		self::assertStringNotContainsStringIgnoringCase('шкал', $prompt);
		self::assertStringNotContainsStringIgnoringCase('баланс', $prompt);
	}

	public function testPlayersListsEveryoneWithLevelAndNick(): void {
		$vasya = $this->player('vasya');
		$this->rate($vasya, 5);
		$this->app->nicks->setOwn($vasya->id, 'Vasya Pro');
		$petya = $this->player('petya');
		$this->app->players->ensureByTelegram(777555, null, 'Без <ника>', $this->clock->now());
		foreach ([$this->owner, $petya] as $viewer) {
			$this->http->reset();
			$this->dispatch($this->privateMessage($viewer, '/players'));
			$text = $this->http->lastText();
			self::assertStringStartsWith('📋 <b>Игроки</b> — ', $text);
			self::assertStringContainsString("<blockquote><b>высокий (5)</b></blockquote>\n<a href=\"https://t.me/vasya\">Vasya Pro</a>\n", $text);
			self::assertStringContainsString('<blockquote><b>Уровень не ставили — считаю средний (3)</b>', $text);
			self::assertStringContainsString("\n<a href=\"https://t.me/petya\">@petya</a>\n", $text);
			self::assertStringContainsString("\n<a href=\"tg://user?id=777555\">Без &lt;ника&gt;</a>\n", $text);
			self::assertStringNotContainsString('без ника', $text);
			self::assertStringNotContainsString('✏️', $text, 'no pencils without a configured link');
			self::assertLessThan(strpos($text, '@petya'), strpos($text, 'Vasya Pro'), 'rated levels first');
			self::assertStringContainsString('Как устроены уровни — /help_level', $text);
			self::assertStringNotContainsString('📊 <b>Уровни игроков</b>', $text, 'no level help in /players');
			self::assertSame(
				$viewer->id === $this->owner->id,
				str_contains($text, 'Поставить уровень: /level @ник 4'),
				'only the owner sees how to set levels in private',
			);
		}

		$this->everyoneIsGroupMember();
		foreach ([$this->owner, $petya] as $viewer) {
			$this->http->reset();
			$this->dispatch($this->groupMessage($viewer, '/players'));
			$text = $this->http->lastText();
			self::assertStringContainsString('Поставить свой ник можно у меня в личке.', $text);
			self::assertStringNotContainsString('Поставить уровень', $text);
		}
	}

	public function testLongPlayersListIsSplit(): void {
		for ($i = 0; $i < 150; $i++) {
			$this->player(sprintf('player_with_a_long_username_%03d', $i));
		}
		$this->http->reset();
		$this->dispatch($this->privateMessage($this->owner, '/players'));
		$sent = array_column($this->http->sent('sendMessage'), 'text');
		self::assertGreaterThan(1, count($sent));
		foreach ($sent as $text) {
			self::assertLessThanOrEqual(4096, mb_strlen($text));
		}
		self::assertStringContainsString('player_with_a_long_username_149', implode("\n", $sent));
	}
}
