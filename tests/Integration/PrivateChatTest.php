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
		$reply = $this->say($vasya, '/help');
		self::assertStringContainsString('Пиши мне обычным языком', $reply);
		self::assertStringNotContainsString('Для админов', $reply);
		self::assertStringNotContainsString('/level @', $reply);
		self::assertStringContainsString('Для админов', $this->say($this->admin, '/help'));
		$owner_help = $this->say($this->owner, '/help');
		self::assertStringContainsString('Только для главного админа', $owner_help);
		self::assertStringContainsString('Уровни игроков', $this->say($vasya, '/levels'));
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

	public function testSeveralGroupsAskForTheGroupAndKeepTheirNumbers(): void {
		$vasya = $this->player('vasya');
		$this->openTournament(3);
		$this->app->chats->ensure(self::SECOND_CHAT, 'Вторая группа');
		$t = $this->app->tournaments->create(new CreateTournament(
			self::SECOND_CHAT,
			$this->owner->id,
			'Кубок второй группы',
			'2026-11-21 19:00',
			2,
			'6',
		));
		$this->app->tournaments->openRegistration($this->owner->id, $t->id);

		$reply = $this->say($vasya, '/tournaments');
		self::assertStringContainsString('👥 Группа <b>P1 турниры</b>', $reply);
		self::assertStringContainsString('👥 Группа <b>Вторая группа</b>', $reply);
		self::assertSame(2, substr_count($reply, '№1'), 'each group keeps its own numbering');

		// /info 1 is ambiguous until a group is chosen.
		self::assertStringContainsString('С какой работаем', $this->say($vasya, '/info 1'));
		self::assertSame(['grp:' . self::CHAT_ID, 'grp:' . self::SECOND_CHAT], $this->lastButtons());
		self::assertStringContainsString('private: ask group (/info)', $this->log->text());

		$this->dispatch($this->press($vasya, 'grp:' . self::SECOND_CHAT));
		self::assertStringContainsString('Кубок второй группы', $this->say($vasya, '/info 1'));

		// A deep link switches the group.
		$this->say($vasya, '/start t_1');
		self::assertStringContainsString('Субботний кубок', $this->say($vasya, '/info 1'));
		self::assertStringContainsString('<b>P1 турниры</b>', $this->say($vasya, '/group'));
	}

	public function testAdminCommandsWorkInPrivate(): void {
		$this->openTournament(3);
		self::assertStringContainsString('закрыта', $this->say($this->admin, '/close 1'));
		self::assertSame('closed', $this->app->tournament_repo->find(1)->status);
		self::assertStringContainsString('private: command /close', $this->log->text());

		$vasya = $this->player('vasya');
		self::assertStringContainsString('только для админов', $this->say($vasya, '/cancel 1'));
		self::assertSame('closed', $this->app->tournament_repo->find(1)->status);
	}

	public function testOwnerCommandsWorkInPrivate(): void {
		$vasya = $this->player('vasya');
		self::assertSame('Записал: @vasya — высокий (5).', $this->say($this->owner, '/level @vasya 5'));
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
		self::assertStringContainsString('Опубликовал! Анонс уже в группе <b>P1 турниры</b>', end($sent)['text']);
		self::assertStringContainsString('private: card reply', $this->log->text());
	}

	public function testCreationAsksForTheGroupWhenAdminOfSeveral(): void {
		$this->app->chats->ensure(self::SECOND_CHAT, 'Вторая группа');
		$reply = $this->say($this->owner, '/new 23.11 20:00 | Кубок | 2 | 3,4');
		self::assertStringContainsString('В какой группе создаём турнир', $reply);
		self::assertSame(['grp:' . self::CHAT_ID, 'grp:' . self::SECOND_CHAT], $this->lastButtons());
		self::assertNull($this->app->tournament_repo->find(1));

		$this->dispatch($this->press($this->owner, 'grp:' . self::SECOND_CHAT));
		$this->say($this->owner, '/new 23.11 20:00 | Кубок | 2 | 3,4');
		self::assertSame(self::SECOND_CHAT, $this->app->tournament_repo->find(1)->chat_id);
	}

	public function testOnlyAdminGroupIsUsedForCreation(): void {
		// admin2 is an admin of the main group only: the second group is not offered.
		$this->app->chats->ensure(self::SECOND_CHAT, 'Вторая группа');
		$this->http->onTelegram('getChatAdministrators', function (array $json): array {
			$admins = $json['chat_id'] === self::CHAT_ID
				? [[
					'status' => 'administrator',
					'user' => ['id' => $this->tgIdOf($this->admin), 'is_bot' => false, 'first_name' => 'A'],
				]]
				: [];
			return ['ok' => true, 'result' => $admins];
		});
		self::assertStringContainsString('Публикуем', $this->say($this->admin, '/new 23.11 20:00 | Кубок | 2 | 3,4'));
		self::assertSame(self::CHAT_ID, $this->app->tournament_repo->find(1)->chat_id);

		$vasya = $this->player('vasya');
		self::assertStringContainsString(
			'только для админов',
			$this->say($vasya, '/new 23.11 20:00 | Кубок | 2 | 3,4'),
		);
	}

	public function testAiCreationAsksForTheGroupWithButtons(): void {
		$this->app->chats->ensure(self::SECOND_CHAT, 'Вторая группа');
		$this->dispatch($this->press($this->owner, 'grp:' . self::CHAT_ID));
		// The owner may create in both groups; the current one is used without asking.
		$this->http->queueOpenAi($this->functionCall('create_tournament', [
			'title' => 'Кубок',
			'starts_at_msk' => '23.11 20:00',
			'team_size' => 2,
			'level' => '7',
		]));
		$card = $this->say($this->owner, 'создай кубок 23.11 в 20, двойки, уровень 7');
		self::assertStringContainsString('Публикуем или что-то поправить?', $card);
		self::assertSame(self::CHAT_ID, $this->app->tournament_repo->find(1)->chat_id);
		self::assertStringContainsString(
			'Разговор в личке. Группа турниров: P1 турниры',
			$this->aiRequests()[0]['json']['input'][0]['content'],
		);
	}

	public function testAiCreationWithoutCurrentGroupShowsGroupButtons(): void {
		$this->app->chats->ensure(self::SECOND_CHAT, 'Вторая группа');
		// The admin of both groups, no group chosen yet: ask before the AI.
		self::assertStringContainsString('С какой работаем', $this->say($this->owner, 'создай кубок'));
		self::assertSame([], $this->aiRequests());

		// The current group is one where he is not an admin: creation offers the groups he can use.
		$third = -100333;
		$this->app->chats->ensure($third, 'Третья группа');
		$this->http->onTelegram('getChatAdministrators', function (array $json) use ($third): array {
			$admins = $json['chat_id'] === $third
				? []
				: [[
					'status' => 'administrator',
					'user' => ['id' => $this->tgIdOf($this->admin), 'is_bot' => false, 'first_name' => 'A'],
				]];
			return ['ok' => true, 'result' => $admins];
		});
		$this->app->players->setDmGroup($this->admin->id, $third);
		$this->http->queueOpenAi($this->functionCall('create_tournament', [
			'title' => 'Кубок',
			'starts_at_msk' => '23.11 20:00',
			'team_size' => 2,
			'level' => '7',
		]));
		$this->http->queueOpenAi($this->text('Выбери группу, пожалуйста.'));
		$reply = $this->say($this->admin, 'создай кубок 23.11 в 20, двойки, уровень 7');
		self::assertSame('Выбери группу, пожалуйста.', $reply);
		self::assertSame(['grp:' . self::CHAT_ID, 'grp:' . self::SECOND_CHAT], $this->lastButtons());
		self::assertNull($this->app->tournament_repo->find(1));
	}

	public function testFreeTextGoesToAiWithAllToolsAndMemory(): void {
		$vasya = $this->player('vasya');
		$t = $this->openTournament(3);
		$this->http->queueOpenAi($this->text('На какой турнир записать?'));
		self::assertSame('На какой турнир записать?', $this->say($vasya, 'хочу поиграть соло'));
		$first = $this->aiRequests()[0]['json'];
		self::assertContains('join_solo', array_column($first['tools'], 'name'));
		self::assertContains('register_team', array_column($first['tools'], 'name'));
		self::assertNotContains('create_tournament', array_column($first['tools'], 'name'));
		self::assertStringContainsString(
			'Разговор в личке. Группа турниров: P1 турниры',
			$first['input'][0]['content'],
		);
		self::assertStringContainsString('private: ai', $this->log->text());

		// The next message continues the dialog without a reply.
		$this->http->queueOpenAi($this->functionCall('join_solo', ['tournament_id' => 1]));
		$this->http->queueOpenAi($this->text('Записал!'));
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

	public function testAiCannotJoinNonMembers(): void {
		$this->openTournament(3);
		$this->http->onTelegram('getChatMember', static function (): array {
			return ['ok' => true, 'result' => ['status' => 'kicked']];
		});
		$stranger = $this->player('stranger');
		$this->http->queueOpenAi($this->functionCall('join_solo', ['tournament_id' => 1]));
		$this->http->queueOpenAi($this->text('Сначала вступи в группу.'));
		$this->say($stranger, 'запиши соло');
		self::assertSame(0, $this->app->tournaments->lineup(1)->occupied());
		$requests = $this->aiRequests();
		self::assertStringContainsString('только участникам группы', $requests[1]['json']['input'][0]['output']);
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
