<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Domain\CreateTournament;
use P1Bot\Domain\Model\Player;
use P1Bot\Support\Logger;
use P1Bot\Tests\Support\IntegrationTestCase;
use P1Bot\Tests\Support\LogCapture;

/**
	Private chat: everything happens here (deep links, buttons, commands, AI with all tools).
*/
class PrivateChatTest extends IntegrationTestCase {
	protected const SECOND_CHAT = -1009876543210;

	protected ?LogCapture $log = null;

	protected function logger(): ?Logger {
		$this->log = new LogCapture();
		return $this->log->logger;
	}

	protected function config(): array {
		$config = parent::config();
		$config['openai']['api_key'] = 'sk-test';
		return $config;
	}

	/**
		@param array<string, mixed> $extra
	*/
	protected function say(Player $from, string $text, array $extra = []): string {
		$this->http->reset();
		$this->dispatch($this->privateMessage($from, $text, $extra));
		$sent = $this->http->sent('sendMessage');
		self::assertNotSame([], $sent, 'the bot must answer in private: ' . $text);
		foreach ($sent as $message) {
			self::assertSame($this->tgIdOf($from), $message['chat_id'], 'answers stay in private');
		}
		return implode("\n---\n", array_column($sent, 'text'));
	}

	/**
		@return list<string> callback_data of the last message's buttons
	*/
	protected function lastButtons(): array {
		$sent = $this->http->sent('sendMessage');
		$rows = end($sent)['reply_markup']['inline_keyboard'] ?? [];
		return array_column(array_merge([], ...$rows), 'callback_data');
	}

	/**
		@param array<string, mixed> $args
		@return array<string, mixed>
	*/
	protected function functionCall(string $name, array $args): array {
		return ['id' => 'r' . mt_rand(), 'output' => [[
			'type' => 'function_call',
			'id' => 'fc_1',
			'call_id' => 'c' . mt_rand(),
			'name' => $name,
			'arguments' => json_encode($args, JSON_UNESCAPED_UNICODE),
		]]];
	}

	/**
		@return array<string, mixed>
	*/
	protected function text(string $text): array {
		return ['id' => 'r' . mt_rand(), 'output' => [[
			'type' => 'message',
			'role' => 'assistant',
			'content' => [['type' => 'output_text', 'text' => $text]],
		]]];
	}

	/**
		@return list<array<string, mixed>>
	*/
	protected function aiRequests(): array {
		return array_values(array_filter($this->http->requests, static function (array $r): bool {
			return str_ends_with($r['url'], '/responses');
		}));
	}

	public function testStartWithTournamentShowsItWithButtons(): void {
		$t = $this->openTournament(3);
		$vasya = $this->player('vasya');
		$reply = $this->say($vasya, '/start t_' . $t->id);
		self::assertStringContainsString('Привет, Vasya!', $reply);
		self::assertStringContainsString('Субботний кубок', $reply);
		self::assertSame(['psolo:' . $t->id, 'pteam:' . $t->id], $this->lastButtons());
		self::assertStringContainsString('private: start t_' . $t->id, $this->log->text());
		self::assertTrue($this->app->players->dmOk($vasya->id), 'started the bot');
		self::assertSame(self::CHAT_ID, $this->app->players->dmGroup($vasya->id));

		// The buttons follow the player's state.
		$this->app->teams->joinSolo($t->id, $vasya->id);
		$this->say($vasya, '/start t_' . $t->id);
		self::assertSame(['punsolo:' . $t->id], $this->lastButtons());

		$cap = $this->player('cap');
		$mate = $this->player('mate');
		$entry = $this->app->teams->createTeam($t->id, $cap->id, 'Wolves', [$mate->id]);
		$this->say($cap, '/start t_' . $t->id);
		self::assertSame(['pdissolve:' . $entry->team->id], $this->lastButtons());
		$this->say($mate, '/start t_' . $t->id);
		self::assertSame(['pleave:' . $entry->team->id], $this->lastButtons());
	}

	public function testStartWithUnknownTournamentShowsTheList(): void {
		$this->openTournament(3);
		$reply = $this->say($this->player('vasya'), '/start t_999');
		self::assertStringContainsString('уже не активен', $reply);
		self::assertStringContainsString('Субботний кубок', $reply);
		self::assertSame(['popen:1'], $this->lastButtons());
	}

	public function testPlainStartGreetsAndListsTournaments(): void {
		$this->openTournament(3);
		$vasya = $this->player('vasya');
		$reply = $this->say($vasya, '/start');
		self::assertStringContainsString('Привет, Vasya!', $reply);
		self::assertStringContainsString('Субботний кубок', $reply);
		self::assertSame(['popen:1'], $this->lastButtons());
		self::assertStringContainsString('private: start ', $this->log->text());

		$this->say($vasya, '/start g_' . self::CHAT_ID);
		self::assertStringContainsString('private: start g_' . self::CHAT_ID, $this->log->text());
	}

	public function testHelpAndLevels(): void {
		$vasya = $this->player('vasya');
		$this->withoutSharedOwnerGroup($vasya);
		$reply = $this->say($vasya, '/help');
		self::assertStringContainsString('Пиши мне обычным языком', $reply);
		self::assertStringNotContainsString('Создание турниров', $reply);
		self::assertStringNotContainsString('/level @user 4', $reply);
		self::assertStringNotContainsString('/group', $reply);
		$this->everyoneIsGroupMember();
		$admin_help = $this->say($this->admin, '/help');
		self::assertStringContainsString('Создание турниров', $admin_help);
		self::assertStringContainsString('только его автор и главный админ', $admin_help);
		self::assertStringNotContainsString('Только для главного админа', $admin_help);
		$owner_help = $this->say($this->owner, '/help');
		self::assertStringContainsString('Только для главного админа', $owner_help);
		self::assertStringContainsString('/level @user 4', $owner_help);
		self::assertStringContainsString('Уровни игроков', $this->say($vasya, '/levels'));
	}

	/**
		Plain «help» (and synonyms) must return full /help, not /levels — and never go through the AI.
	*/
	public function testPlainHelpTextReturnsFullHelpNotLevels(): void {
		$vasya = $this->player('vasya');
		$this->withoutSharedOwnerGroup($vasya);
		$slash = $this->say($vasya, '/help');
		foreach (['help', 'Help!', 'help me', 'HELP ME?', 'помощь', 'справка.', 'что ты умеешь', 'что умеешь?'] as $phrase) {
			$this->http->reset();
			$reply = $this->say($vasya, $phrase);
			self::assertSame($slash, $reply, 'phrase: ' . $phrase);
			self::assertStringNotContainsString('Уровни игроков', $reply, 'phrase: ' . $phrase);
			self::assertSame([], array_filter($this->http->requests, static function (array $r): bool {
				return str_contains($r['url'], '/responses') || str_contains($r['url'], 'api.openai.com');
			}), 'AI must not run for: ' . $phrase);
		}

		$owner_slash = $this->say($this->owner, '/help');
		$owner_plain = $this->say($this->owner, 'help');
		self::assertSame($owner_slash, $owner_plain);
		self::assertStringContainsString('Только для главного админа', $owner_plain);
	}

	public function testTournamentListShowsOwnDraftsAndOpenButtons(): void {
		$vasya = $this->player('vasya');
		$this->openTournament(3);
		$this->app->tournaments->create(new CreateTournament(
			self::CHAT_ID,
			$this->owner->id,
			'Черновик владельца',
			'2026-11-20 19:00',
			3,
			'10',
		));

		$reply = $this->say($vasya, '/tournaments');
		self::assertStringContainsString('Субботний кубок', $reply);
		self::assertStringNotContainsString('Черновик владельца', $reply, 'drafts are hidden from others');
		self::assertStringNotContainsString('Группа', $reply, 'one group: no group header');
		self::assertSame(['popen:1'], $this->lastButtons());

		$owner_reply = $this->say($this->owner, '/tournaments');
		self::assertStringContainsString('Черновик владельца', $owner_reply);
		self::assertSame(['popen:1', 'popen:2'], $this->lastButtons());
	}

	public function testSeveralGroupsUseGlobalNumbers(): void {
		$vasya = $this->player('vasya');
		$this->openTournament(3); // id 1
		$this->app->chats->ensure(self::SECOND_CHAT, 'Вторая группа');
		$t = $this->app->tournaments->create(new CreateTournament(
			self::SECOND_CHAT,
			$this->owner->id,
			'Кубок второй группы',
			'2026-11-21 19:00',
			2,
			'6',
		));
		$this->app->tournaments->openRegistration($this->owner->id, $t->id); // id 2

		$reply = $this->say($vasya, '/tournaments');
		self::assertStringNotContainsString('👥 Группа', $reply, 'global list: no per-group headers');
		self::assertStringContainsString('· №1', $reply);
		self::assertStringContainsString('· №2', $reply);

		// Global numbers: /info N works without picking a group.
		self::assertStringContainsString('Субботний кубок', $this->say($vasya, '/info 1'));
		self::assertStringContainsString('Кубок второй группы', $this->say($vasya, '/info 2'));

		$this->say($vasya, '/start t_1');
		self::assertStringContainsString('Субботний кубок', $this->http->lastText());
		self::assertStringContainsString('Такой команды я не знаю', $this->say($vasya, '/group'));
	}

	public function testAdminCommandsWorkInPrivate(): void {
		$this->openTournament(3);
		// Chat admin who is not the author cannot manage the tournament.
		self::assertStringContainsString('автор или главный админ', $this->say($this->admin, '/close 1'));
		self::assertSame('registration', $this->app->tournament_repo->find(1)->status);

		self::assertStringContainsString('закрыта', $this->say($this->owner, '/close 1'));
		self::assertSame('closed', $this->app->tournament_repo->find(1)->status);

		$vasya = $this->player('vasya');
		self::assertStringContainsString('автор или главный админ', $this->say($vasya, '/cancel 1'));
		self::assertSame('closed', $this->app->tournament_repo->find(1)->status);
	}

	public function testOwnerCommandsWorkInPrivate(): void {
		$vasya = $this->player('vasya');
		self::assertSame('Записала: @vasya — высокий (5).', $this->say($this->owner, '/level @vasya 5'));
		self::assertSame(5, $this->app->balances->levelOf($this->app->balances->findDefault()->id, $vasya->id));
		self::assertStringContainsString('@vasya — высокий (5)', $this->say($this->owner, '/balance'));
		self::assertStringContainsString('только главный админ', $this->say($this->admin, '/level @vasya 2'));
	}

	public function testCreationInPrivatePublishesToTheGroup(): void {
		$card = $this->say($this->admin, '/new 23.11 20:00 | Кубок | 2 | 3,4');
		self::assertStringContainsString('Публикуем или что-то поправить?', $card);
		$t = $this->app->tournament_repo->find(1);
		self::assertSame(self::CHAT_ID, $t->chat_id, 'the draft belongs to the group');

		// «да» in reply to the private card publishes to the group.
		$this->http->reset();
		$this->dispatch($this->privateMessage($this->admin, 'да', ['reply_to_message' => [
			'message_id' => $t->card_msg_id,
			'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Bot'],
		]]));
		self::assertSame('registration', $this->app->tournament_repo->find(1)->status);
		$sent = $this->http->sent('sendMessage');
		self::assertSame(self::CHAT_ID, $sent[0]['chat_id']);
		self::assertSame('💬 Записаться / вопросы в личке', $sent[0]['reply_markup']['inline_keyboard'][0][0]['text']);
		self::assertStringContainsString('Опубликовала! Анонс уже во всех группах', end($sent)['text']);
		self::assertStringContainsString('private: card reply', $this->log->text());
	}

	public function testCreationIsGlobalWithoutGroupPicker(): void {
		$this->app->chats->ensure(self::SECOND_CHAT, 'Вторая группа');
		$reply = $this->say($this->owner, '/new 23.11 20:00 | Кубок | 2 | 3,4');
		self::assertStringContainsString('Публикуем', $reply);
		self::assertSame(['pub:1', 'fix:1'], $this->lastButtons());
		$t = $this->app->tournament_repo->find(1);
		self::assertNotNull($t);
		self::assertContains($t->chat_id, [self::CHAT_ID, self::SECOND_CHAT]);
	}

	public function testCreateRequiresSharedGroupWithOwner(): void {
		self::assertStringContainsString('Публикуем', $this->say($this->admin, '/new 23.11 20:00 | Кубок | 2 | 3,4'));
		self::assertNotNull($this->app->tournament_repo->find(1));

		$vasya = $this->player('vasya');
		$this->withoutSharedOwnerGroup($vasya);
		self::assertStringContainsString(
			'организатор P1',
			$this->say($vasya, '/new 23.11 20:00 | Кубок | 2 | 3,4'),
		);
		self::assertStringContainsString('Записаться на турнир при этом можно', $this->http->lastText());
	}

	public function testAiCreationIsGlobalWithoutGroupPicker(): void {
		$this->app->chats->ensure(self::SECOND_CHAT, 'Вторая группа');
		$this->http->queueOpenAi($this->functionCall('create_tournament', [
			'title' => 'Кубок',
			'starts_at_msk' => '23.11 20:00',
			'team_size' => 2,
			'level' => '7',
		]));
		$card = $this->say($this->owner, 'создай кубок 23.11 в 20, двойки, уровень 7');
		self::assertStringContainsString('Публикуем или что-то поправить?', $card);
		self::assertNotNull($this->app->tournament_repo->find(1));
		self::assertSame(['pub:1', 'fix:1'], $this->lastButtons());
	}

	public function testAiCreationRefusesWithoutSharedOwnerGroup(): void {
		$vasya = $this->player('vasya');
		$this->withoutSharedOwnerGroup($vasya);
		self::assertStringContainsString('организатор P1', $this->say($vasya, '/new 23.11 20:00 | Кубок | 2 | 3,4'));
		self::assertNull($this->app->tournament_repo->find(1));
	}

	public function testFreeTextGoesToAiWithAllToolsAndMemory(): void {
		$vasya = $this->player('vasya');
		$this->ownerAbsentFromGroups();
		$t = $this->openTournament(3);
		$this->http->queueOpenAi($this->text('На какой турнир записать?'));
		self::assertSame('На какой турнир записать?', $this->say($vasya, 'хочу поиграть соло'));
		$first = $this->aiRequests()[0]['json'];
		self::assertContains('join_solo', array_column($first['tools'], 'name'));
		self::assertContains('register_team', array_column($first['tools'], 'name'));
		self::assertNotContains('create_tournament', array_column($first['tools'], 'name'));
		self::assertStringContainsString(
			'Номера турниров общие для всех групп',
			$first['input'][0]['content'],
		);
		self::assertStringContainsString('private: ai', $this->log->text());

		// The next message continues the dialog without a reply.
		$this->http->queueOpenAi($this->functionCall('join_solo', ['tournament_id' => 1]));
		$this->http->queueOpenAi($this->text('Записала!'));
		$this->say($vasya, 'на субботний');
		$input = $this->aiRequests()[0]['json']['input'];
		self::assertSame(['role' => 'user', 'content' => 'хочу поиграть соло'], $input[1]);
		self::assertSame(['role' => 'assistant', 'content' => 'На какой турнир записать?'], $input[2]);
		self::assertSame('main', $this->app->tournaments->lineup($t->id)->placementOf($vasya->id));
		$edits = $this->http->sent('editMessageText');
		self::assertSame([], array_filter($this->http->sent('sendMessage'), static function (array $m): bool {
			return $m['chat_id'] === self::CHAT_ID;
		}), 'nothing is posted to the group about the join');
		self::assertIsArray($edits);

		// After the TTL the dialog is forgotten.
		$this->clock->advance('+31 minutes');
		$this->http->queueOpenAi($this->text('Привет!'));
		$this->say($vasya, 'и ещё');
		self::assertCount(2, $this->aiRequests()[0]['json']['input']);
	}

	public function testTeamButtonThenAiRegistersTheTeam(): void {
		$t = $this->openTournament(3);
		$cap = $this->player('cap');
		$this->player('vasya');
		$this->player('petya');
		$this->dispatch($this->press($cap, 'pteam:' . $t->id));

		$this->http->queueOpenAi($this->functionCall('register_team', [
			'tournament_id' => 1,
			'team_name' => 'Wolves',
			'players' => ['@vasya', '@petya'],
		]));
		$this->http->queueOpenAi($this->text('Команда Wolves записана!'));
		self::assertSame('Команда Wolves записана!', $this->say($cap, 'Wolves: @vasya, @petya'));
		$input = $this->aiRequests()[0]['json']['input'];
		self::assertStringContainsString('Собираем команду на турнир №1', $input[1]['content']);
		self::assertNotNull($this->app->teams->findByName($t->id, 'Wolves'));
	}

	public function testAiJoinDoesNotRequireGroupMembership(): void {
		$this->openTournament(3);
		$this->http->onTelegram('getChatMember', static function (): array {
			return ['ok' => true, 'result' => ['status' => 'kicked']];
		});
		$stranger = $this->player('stranger');
		$this->http->queueOpenAi($this->functionCall('join_solo', ['tournament_id' => 1]));
		$this->http->queueOpenAi($this->text('Записала!'));
		$this->say($stranger, 'запиши соло');
		self::assertSame(1, $this->app->tournaments->lineup(1)->occupied());
	}

	public function testUnknownCommandAndEditedMessage(): void {
		$vasya = $this->player('vasya');
		self::assertStringContainsString('Такой команды я не знаю', $this->say($vasya, '/foo'));

		$raw = $this->privateMessage($vasya, 'исправил');
		$raw['edited_message'] = $raw['message'];
		unset($raw['message']);
		$this->http->reset();
		$this->dispatch($raw);
		$this->dispatch($raw);
		self::assertCount(1, $this->http->sent('sendMessage'), 'replied once');
		self::assertStringContainsString('Исправленные сообщения я не вижу', $this->http->lastText());
	}

	public function testAiErrorGivesFriendlyText(): void {
		$vasya = $this->player('vasya');
		self::assertSame('Что-то пошло не так, попробуй ещё раз чуть позже.', $this->say($vasya, 'привет')); // queue empty: 500
	}
}
