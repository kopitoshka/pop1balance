<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\App\RegistrationFillFlow;
use P1Bot\App\WebAppPicker;
use P1Bot\Handlers\Context;
use P1Bot\Telegram\Message;
use P1Bot\Telegram\WebAppInitData;
use P1Bot\Tests\Support\IntegrationTestCase;
use P1Bot\View\DeepLink;

class RegistrationFillTest extends IntegrationTestCase {

	protected function config(): array {
		$c = parent::config();
		$c['telegram']['webhook_url'] = 'https://pop1.example.com/webhook.php';
		$c['openai']['api_key'] = 'sk-test';
		$c['paths'] = ['prompt' => dirname(__DIR__, 2) . '/config/prompts/system.md'];
		return $c;
	}

	public function testTryResolveSuggestsByDisplayName(): void {
		$ksu = $this->named('ksusha_tg', 'Ксюша');
		$probe = $this->app->player_resolver->tryResolve('Ксюша', $this->owner);
		self::assertNotNull($probe['player']);
		self::assertSame($ksu->id, $probe['player']->id);
		$unknown = $this->app->player_resolver->tryResolve('Ксюш', $this->owner);
		self::assertNull($unknown['player']);
		$ids = array_map(static fn ($p) => $p->id, $unknown['suggestions']);
		self::assertContains($ksu->id, $ids);
	}

	public function testAiRegisterAsksForMissingWithButtonsAndNoSecondModelRound(): void {
		$t = $this->openTournament(3, '3,3,4');
		$cap = $this->player('sectacap');
		$ksu = $this->named('ksusha_tg', 'Ксюша Петрова');
		$stas = $this->named('stas_tg', 'Стас Иванов');
		$this->ownerAbsentFromGroups();
		$this->http->queueOpenAi($this->functionCall('resp_1', 'call_1', 'register_team', [
			'tournament_id' => $t->id,
			'team_name' => 'секта',
			'players' => ['Ксюша', 'Стас'],
		]));

		$this->dispatch($this->privateMessage($cap, 'запиши команду секта с Ксюшей и Стасом'));

		self::assertNull($this->app->teams->findByName($t->id, 'секта'));
		$open_ai = array_values(array_filter(
			$this->http->requests,
			static fn (array $r): bool => str_ends_with($r['url'], '/responses'),
		));
		self::assertCount(1, $open_ai, 'fixed say must skip the extra model round');

		$text = $this->sentPlain();
		self::assertStringContainsString('Не нашла', $text);
		self::assertStringContainsString('Ксюша', $text);
		self::assertStringContainsString('Стас', $text);
		self::assertStringContainsString('секта', $text);
		self::assertStringContainsString('@username', $text);

		$inline = $this->firstInlineKeyboard();
		$texts = $this->buttonTexts($inline);
		self::assertTrue((bool) array_filter($texts, static fn ($x) => str_contains($x, 'Ксюша') || str_contains($x, '@ksusha_tg')));
		self::assertTrue((bool) array_filter($texts, static fn ($x) => str_contains($x, 'Стас') || str_contains($x, '@stas_tg')));
		self::assertContains(RegistrationFillFlow::BUTTON_TEXT, $texts);
		self::assertContains('✖️ Отмена', $texts);
		self::assertNotNull($this->webAppButton($inline));

		$picker = false;
		foreach ($this->http->sent('sendMessage') as $msg) {
			foreach ($msg['reply_markup']['keyboard'] ?? [] as $row) {
				foreach ($row as $btn) {
					if (($btn['text'] ?? '') === '👤 Выбрать из Telegram') {
						self::assertSame(RegistrationFillFlow::REQUEST_USERS_ID, $btn['request_users']['request_id']);
						self::assertSame(2, $btn['request_users']['max_quantity']);
						$picker = true;
					}
				}
			}
		}
		self::assertTrue($picker, 'request_users follow-up must be sent in private');
		unset($ksu, $stas);
	}

	public function testSuggestionThenUsernameCompletesRegistration(): void {
		$t = $this->openTournament(3, '3,3,4');
		$cap = $this->player('sectacap2');
		$ksu = $this->named('ksusha2', 'Ксюша Петрова');
		$stas = $this->named('stas2', 'Стас Иванов');
		$this->startFill($cap, $t->id, ['Ксюша', 'Стас'], 'секта');

		$data = $this->callbackStartingWith('rfill:');
		self::assertNotNull($data);
		$this->http->reset();
		$this->dispatch($this->press($cap, $data));
		self::assertNull($this->app->teams->findByName($t->id, 'секта'), 'one of two still missing');
		self::assertTrue($this->app->reg_fill->hasPending($cap->id, $this->tgIdOf($cap)));

		$this->http->reset();
		$this->dispatch($this->privateMessage($cap, '@stas2'));
		$entry = $this->app->teams->findByName($t->id, 'секта');
		self::assertNotNull($entry);
		self::assertSame($cap->id, $entry->team->captain_id);
		$ids = array_map(static fn ($m) => $m->player_id, $entry->members);
		self::assertEqualsCanonicalizing([$cap->id, $ksu->id, $stas->id], $ids);
		self::assertFalse($this->app->reg_fill->hasPending($cap->id, $this->tgIdOf($cap)));
		self::assertStringContainsString('Записала команду', $this->sentPlain());
	}

	public function testTextUsernamesCompleteInOneShot(): void {
		$t = $this->openTournament(3, '3,3,4');
		$cap = $this->player('sectacap3');
		$ksu = $this->named('ksusha3', 'Ксюша Петрова');
		$stas = $this->named('stas3', 'Стас Иванов');
		$this->startFill($cap, $t->id, ['Ксюша', 'Стас'], 'секта');
		$this->http->reset();
		$this->dispatch($this->privateMessage($cap, '@ksusha3 @stas3'));
		$entry = $this->app->teams->findByName($t->id, 'секта');
		self::assertNotNull($entry);
		$ids = array_map(static fn ($m) => $m->player_id, $entry->members);
		self::assertEqualsCanonicalizing([$cap->id, $ksu->id, $stas->id], $ids);
	}

	public function testWebAppSubmitFillsRegistration(): void {
		$t = $this->openTournament(3, '3,3,4');
		$cap = $this->player('sectacap4');
		$ksu = $this->named('ksusha4', 'Ксюша Петрова');
		$stas = $this->named('stas4', 'Стас Иванов');
		$this->startFill($cap, $t->id, ['Ксюша', 'Стас'], 'секта');
		$btn = $this->webAppButton($this->firstInlineKeyboard());
		self::assertIsArray($btn);
		$token = $this->tokenFromButton($btn);
		$init = $this->initDataFor($cap);
		$boot = $this->app->webapp_picker->bootstrap($init, $token);
		self::assertSame(WebAppPicker::ACTION_REG_FILL, $boot['action']);
		self::assertSame(2, $boot['max_select']);
		$this->http->reset();
		$result = $this->app->webapp_picker->submit($init, $token, [$ksu->id, $stas->id], null);
		self::assertTrue($result['close']);
		$entry = $this->app->teams->findByName($t->id, 'секта');
		self::assertNotNull($entry);
		$ids = array_map(static fn ($m) => $m->player_id, $entry->members);
		self::assertEqualsCanonicalizing([$cap->id, $ksu->id, $stas->id], $ids);
	}

	public function testUsersSharedFillsRegistration(): void {
		$t = $this->openTournament(3, '3,3,4');
		$cap = $this->player('sectacap5');
		$ksu = $this->named('ksusha5', 'Ксюша Петрова');
		$stas = $this->named('stas5', 'Стас Иванов');
		$this->startFill($cap, $t->id, ['Ксюша', 'Стас'], 'секта');
		$this->http->reset();
		$this->dispatch([
			'update_id' => 910,
			'message' => [
				'message_id' => 81,
				'chat' => ['id' => $this->tgIdOf($cap), 'type' => 'private'],
				'from' => [
					'id' => $this->tgIdOf($cap),
					'is_bot' => false,
					'first_name' => $cap->display_name,
					'username' => $cap->username,
				],
				'date' => 0,
				'users_shared' => [
					'request_id' => RegistrationFillFlow::REQUEST_USERS_ID,
					'users' => [
						[
							'user_id' => $ksu->tg_user_id,
							'first_name' => 'Ксюша',
							'username' => 'ksusha5',
						],
						[
							'user_id' => $stas->tg_user_id,
							'first_name' => 'Стас',
							'username' => 'stas5',
						],
					],
				],
			],
		]);
		$entry = $this->app->teams->findByName($t->id, 'секта');
		self::assertNotNull($entry);
		$ids = array_map(static fn ($m) => $m->player_id, $entry->members);
		self::assertEqualsCanonicalizing([$cap->id, $ksu->id, $stas->id], $ids);
	}

	public function testGroupMarkupHasSuggestionsAndPrivateLinkButNoWebApp(): void {
		$t = $this->openTournament(3, '3,3,4');
		$cap = $this->player('sectacap6');
		$this->named('ksusha6', 'Ксюша Петрова');
		$this->named('stas6', 'Стас Иванов');
		$msg = Message::fromArray([
			'message_id' => 1,
			'chat' => ['id' => self::CHAT_ID, 'type' => 'supergroup', 'title' => 'P1'],
			'from' => [
				'id' => $this->tgIdOf($cap),
				'is_bot' => false,
				'first_name' => $cap->display_name,
				'username' => $cap->username,
			],
			'date' => 0,
			'text' => 'запиши секту',
		]);
		$ctx = new Context($msg, $cap, false, false, self::CHAT_ID, 'P1');
		$result = $this->app->dispatcher->dispatch('register_team', [
			'tournament_id' => $t->id,
			'team_name' => 'секта',
			'players' => ['Ксюша', 'Стас'],
		], $ctx);
		self::assertStringContainsString('Не нашла', (string) ($result['say'] ?? ''));
		self::assertStringContainsString('в личке', (string) ($result['say'] ?? ''));
		self::assertNull($result['followup'] ?? null);
		$rows = $result['suggest_markup']['reply_markup']['inline_keyboard'] ?? [];
		$texts = $this->buttonTexts($rows);
		self::assertContains('✉️ Продолжить в личке', $texts);
		self::assertContains('✖️ Отмена', $texts);
		self::assertNotContains(RegistrationFillFlow::BUTTON_TEXT, $texts);
		$url = null;
		foreach ($rows as $row) {
			foreach ($row as $btn) {
				if (($btn['text'] ?? '') === '✉️ Продолжить в личке') {
					$url = (string) ($btn['url'] ?? '');
				}
			}
		}
		self::assertNotNull($url);
		self::assertStringContainsString('?start=rf_', $url);
		$parsed = DeepLink::parse((string) preg_replace('/^.*\?start=/', '', $url));
		self::assertNotNull($parsed['reg_fill']);
	}

	public function testCancelClearsPending(): void {
		$t = $this->openTournament(3, '3,3,4');
		$cap = $this->player('sectacap7');
		$this->named('ksusha7', 'Ксюша Петрова');
		$this->startFill($cap, $t->id, ['Ксюша'], 'секта');
		self::assertTrue($this->app->reg_fill->hasPending($cap->id, $this->tgIdOf($cap)));
		$this->dispatch($this->press($cap, 'pcancel'));
		self::assertFalse($this->app->reg_fill->hasPending($cap->id, $this->tgIdOf($cap)));
		self::assertNull($this->app->teams->findByName($t->id, 'секта'));
	}

	public function testOrganizerMissingCaptainStartsFill(): void {
		$t = $this->openTournament(3, '3,3,4');
		$vasya = $this->named('vasya8', 'Вася');
		$ctx = $this->privateContext($this->owner);
		$result = $this->app->dispatcher->dispatch('register_team', [
			'tournament_id' => $t->id,
			'team_name' => 'секта',
			'captain' => 'Вас',
			'players' => [],
		], $ctx);
		self::assertNull($this->app->teams->findByName($t->id, 'секта'));
		self::assertStringContainsString('Не нашла', (string) ($result['say'] ?? ''));
		self::assertStringContainsString('Вас', (string) ($result['say'] ?? ''));
		$rows = $result['suggest_markup']['reply_markup']['inline_keyboard'] ?? [];
		$texts = $this->buttonTexts($rows);
		self::assertTrue((bool) array_filter($texts, static fn ($x) => str_contains($x, 'Капитан') || str_contains($x, '@vasya8')));
		self::assertContains(RegistrationFillFlow::BUTTON_TEXT, $texts);
		unset($vasya);
	}

	public function testOrganizerCaptainOfferIncludesWebApp(): void {
		$t = $this->openTournament(3, '3,3,4');
		$this->dispatch($this->press($this->owner, 'otnew:' . $t->id));
		$this->dispatch($this->privateMessage($this->owner, 'Сектачи'));
		$found = false;
		foreach ($this->http->sent('sendMessage') as $msg) {
			foreach ($msg['reply_markup']['inline_keyboard'] ?? [] as $row) {
				foreach ($row as $btn) {
					if (($btn['text'] ?? '') === WebAppPicker::BUTTON_TEXT && isset($btn['web_app']['url'])) {
						$found = true;
						$token = $this->tokenFromButton($btn);
						$boot = $this->app->webapp_picker->bootstrap($this->initDataFor($this->owner), $token);
						self::assertSame(WebAppPicker::ACTION_ORG_CAPTAIN, $boot['action']);
						self::assertSame(1, $boot['max_select']);
					}
				}
			}
		}
		self::assertTrue($found, 'organizer captain pick must include Mini App button');
	}

	public function testDeepLinkParseRegFill(): void {
		self::assertSame(15, DeepLink::parse('rf_15')['reg_fill']);
		self::assertNull(DeepLink::parse('t_3')['reg_fill']);
		self::assertSame(3, DeepLink::parse('t_3')['tournament']);
	}

	public function testJoinNames(): void {
		self::assertSame('Ксюша и Стас', RegistrationFillFlow::joinNames(['Ксюша', 'Стас']));
		self::assertSame('А', RegistrationFillFlow::joinNames(['А']));
	}

	public function testCompactMatchTiredAngelAndStas66(): void {
		$angel = $this->named('just_tired_angel', 'Angel');
		$stas = $this->named('rustas66', 'Stas');
		$cap = $this->player('sectacap8');
		$angel_hit = $this->app->player_resolver->tryResolve('tired angel', $cap);
		self::assertNotNull($angel_hit['player']);
		self::assertSame($angel->id, $angel_hit['player']->id);
		$stas_hit = $this->app->player_resolver->tryResolve('stas66', $cap);
		self::assertNotNull($stas_hit['player']);
		self::assertSame($stas->id, $stas_hit['player']->id);
	}

	public function testSafetyNetConvertsExactUsernameAskIntoRegistration(): void {
		$t = $this->openTournament(3, '3,3,4');
		$cap = $this->player('sectacap9');
		$angel = $this->named('just_tired_angel', 'Angel');
		$stas = $this->named('rustas66', 'Stas');
		$this->ownerAbsentFromGroups();
		$this->http->queueOpenAi($this->text(
			'resp_1',
			'Для команды «секта» нужны Telegram-ники Ксюши и Стаса в формате @username. «tired angel» и «stas66» — это их ники в игре или Telegram-ники?',
		));
		$this->dispatch($this->privateMessage(
			$cap,
			'запиши команду «секта» с Ксюшей и Стасом, ники tired angel и stas66',
		));
		$plain = $this->sentPlain();
		self::assertStringNotContainsString('это их ники в игре или Telegram-ники', $plain);
		$entry = $this->app->teams->findByName($t->id, 'секта');
		if ($entry !== null) {
			$ids = array_map(static fn ($m) => $m->player_id, $entry->members);
			self::assertEqualsCanonicalizing([$cap->id, $angel->id, $stas->id], $ids);
			return;
		}
		self::assertStringContainsString('Не нашла', $plain);
		$texts = $this->buttonTexts($this->firstInlineKeyboard());
		self::assertContains(\P1Bot\App\RegistrationFillFlow::BUTTON_TEXT, $texts);
		self::assertContains('✖️ Отмена', $texts);
	}

	public function testSafetyNetShowsButtonsWhenNicksAreUnknown(): void {
		$t = $this->openTournament(3, '3,3,4');
		$cap = $this->player('sectacap10');
		$this->ownerAbsentFromGroups();
		$this->http->queueOpenAi($this->text(
			'resp_1',
			'Для команды «секта» нужны Telegram-ники Ксюши и Стаса в формате @username. «tired angel» и «stas66» — это их ники в игре или Telegram-ники?',
		));
		$this->dispatch($this->privateMessage(
			$cap,
			'запиши команду «секта» с Ксюшей и Стасом',
		));
		$plain = $this->sentPlain();
		self::assertStringNotContainsString('это их ники в игре или Telegram-ники', $plain);
		self::assertNull($this->app->teams->findByName($t->id, 'секта'));
		self::assertStringContainsString('Не нашла', $plain);
		$texts = $this->buttonTexts($this->firstInlineKeyboard());
		self::assertContains(\P1Bot\App\RegistrationFillFlow::BUTTON_TEXT, $texts);
		self::assertContains('✖️ Отмена', $texts);
		self::assertTrue($this->app->reg_fill->hasPending($cap->id, $this->tgIdOf($cap)));
	}

	/**
		@return array<string, mixed>
	*/
	protected function text(string $id, string $text): array {
		return ['id' => $id, 'output' => [[
			'type' => 'message',
			'role' => 'assistant',
			'content' => [['type' => 'output_text', 'text' => $text]],
		]]];
	}

	/**
		@param list<string> $players
	*/
	protected function startFill(\P1Bot\Domain\Model\Player $cap, int $tournament_id, array $players, string $name): void {
		$this->ownerAbsentFromGroups();
		$this->http->queueOpenAi($this->functionCall('resp_1', 'call_1', 'register_team', [
			'tournament_id' => $tournament_id,
			'team_name' => $name,
			'players' => $players,
		]));
		$this->dispatch($this->privateMessage($cap, 'запиши команду ' . $name));
	}

	protected function named(string $username, string $display): \P1Bot\Domain\Model\Player {
		return $this->app->players->ensureByTelegram(
			$this->next_tg_id++,
			$username,
			$display,
			$this->clock->now(),
		);
	}

	protected function privateContext(\P1Bot\Domain\Model\Player $actor): Context {
		$msg = Message::fromArray([
			'message_id' => 1,
			'chat' => ['id' => $this->tgIdOf($actor), 'type' => 'private'],
			'from' => [
				'id' => $this->tgIdOf($actor),
				'is_bot' => false,
				'first_name' => $actor->display_name,
				'username' => $actor->username,
			],
			'date' => 0,
			'text' => 'hi',
		]);
		return new Context($msg, $actor, true, true, null, '');
	}

	/**
		@return list<list<array<string, mixed>>>
	*/
	protected function sentPlain(): string {
		$parts = [];
		foreach ($this->http->sent('sendMessage') as $msg) {
			$parts[] = html_entity_decode((string) ($msg['text'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		}
		return implode("\n", $parts);
	}

	protected function firstInlineKeyboard(): array {
		foreach ($this->http->sent('sendMessage') as $msg) {
			$rows = $msg['reply_markup']['inline_keyboard'] ?? null;
			if (is_array($rows) && $rows !== []) {
				return $rows;
			}
		}
		return [];
	}

	/**
		@param list<list<array<string, mixed>>> $rows
		@return list<string>
	*/
	protected function buttonTexts(array $rows): array {
		$out = [];
		foreach ($rows as $row) {
			foreach ($row as $btn) {
				$out[] = (string) ($btn['text'] ?? '');
			}
		}
		return $out;
	}

	/**
		@param list<list<array<string, mixed>>> $rows
		@return array<string, mixed>|null
	*/
	protected function webAppButton(array $rows): ?array {
		foreach ($rows as $row) {
			foreach ($row as $btn) {
				if (isset($btn['web_app']['url'])) {
					return $btn;
				}
			}
		}
		return null;
	}

	protected function callbackStartingWith(string $prefix): ?string {
		foreach ($this->firstInlineKeyboard() as $row) {
			foreach ($row as $btn) {
				$data = (string) ($btn['callback_data'] ?? '');
				if (str_starts_with($data, $prefix)) {
					return $data;
				}
			}
		}
		return null;
	}

	protected function tokenFromButton(array $btn): string {
		$url = $btn['web_app']['url'] ?? '';
		parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
		return (string) ($q['ctx'] ?? '');
	}

	protected function initDataFor(\P1Bot\Domain\Model\Player $player): string {
		return WebAppInitData::sign([
			'auth_date' => (string) $this->clock->now()->getTimestamp(),
			'user' => (string) json_encode([
				'id' => $player->tg_user_id,
				'first_name' => $player->display_name,
				'username' => $player->username,
			], JSON_UNESCAPED_UNICODE),
		], (string) $this->config()['telegram']['token']);
	}

	/**
		@return array<string, mixed>
	*/
	protected function functionCall(string $id, string $call_id, string $name, array $args): array {
		return ['id' => $id, 'output' => [
			['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []],
			[
				'type' => 'function_call',
				'id' => 'fc_1',
				'call_id' => $call_id,
				'name' => $name,
				'arguments' => json_encode($args, JSON_UNESCAPED_UNICODE),
			],
		]];
	}
}
