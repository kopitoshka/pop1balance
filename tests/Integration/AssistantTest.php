<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Ai\Tools;
use P1Bot\Handlers\MessageHandler;
use P1Bot\Tests\Support\IntegrationTestCase;

class AssistantTest extends IntegrationTestCase {

	protected function config(): array {
		$config = parent::config();
		$config['openai']['api_key'] = 'sk-test';
		$config['paths'] = ['prompt' => dirname(__DIR__, 2) . '/config/prompts/system.md'];
		return $config;
	}

	public function testReplyContinuesDialogAndExpires(): void {
		$this->openTournament();
		$vasya = $this->player('vasya');
		$this->http->queueOpenAi($this->text('r1', 'Какой турнир имеешь в виду?'));
		$this->dispatch($this->privateMessage($vasya, '@' . self::BOT_USERNAME . ' расскажи про турнир'));
		$bot_message_id = $this->lastSentMessageId();

		$this->http->queueOpenAi($this->text('r2', 'Это субботний кубок.'));
		$this->dispatch($this->privateMessage($vasya, 'на субботний', ['reply_to_message' => [
			'message_id' => $bot_message_id,
			'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Bot'],
		]]));
		$input = $this->lastOpenAiRequest()['input'];
		self::assertSame(['role' => 'user', 'content' => 'расскажи про турнир'], $input[1]);
		self::assertSame(['role' => 'assistant', 'content' => 'Какой турнир имеешь в виду?'], $input[2]);
		self::assertSame(['role' => 'user', 'content' => 'на субботний'], $input[3]);

		$bot_message_id = $this->lastSentMessageId();
		$this->http->queueOpenAi($this->text('r3', 'Привет!'));
		$this->dispatch($this->privateMessage($this->player('petya'), 'а я?', ['reply_to_message' => [
			'message_id' => $bot_message_id,
			'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Bot'],
		]]));
		self::assertCount(2, $this->lastOpenAiRequest()['input']);

		$this->clock->advance('+31 minutes');
		$this->http->queueOpenAi($this->text('r4', 'Ок'));
		$this->dispatch($this->privateMessage($vasya, 'и ещё', ['reply_to_message' => [
			'message_id' => $bot_message_id,
			'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Bot'],
		]]));
		self::assertCount(2, $this->lastOpenAiRequest()['input']);
	}

	public function testRemovedToolsAreNotOfferedAndAreRefused(): void {
		$vasya = $this->player('vasya');
		$this->http->queueOpenAi($this->functionCall('r1', 'c1', 'create_tournament', [
			'title' => 'Хак',
			'starts_at_msk' => '23.11 20:00',
			'team_size' => 2,
			'level' => '10',
		]));
		$this->http->queueOpenAi($this->text('r2', 'Создать турнир можно командой /create_tournament.'));
		$this->dispatch($this->privateMessage($vasya, '@' . self::BOT_USERNAME . ' создай турнир'));

		self::assertNull($this->app->tournament_repo->find(1));
		$tools = array_column($this->firstOpenAiRequest()['tools'], 'name');
		self::assertSame(Tools::names(), $tools);
		foreach ([
			'create_tournament', 'edit_tournament', 'publish_tournament', 'cancel_tournament',
			'open_registration', 'set_max_players', 'join_solo', 'leave_solo', 'leave_team',
			'withdraw_team', 'set_nick', 'set_level', 'assign_nick', 'register_team', 'add_player',
			'remove_player', 'replace_player', 'rename_team', 'transfer_captain',
		] as $gone) {
			self::assertNotContains($gone, $tools, $gone);
		}
		foreach ([
			'help', 'list_tournaments', 'list_history', 'tournament_info', 'levels_help',
			'player_level', 'list_player_levels', 'list_players', 'show_nick', 'my_status',
			'ask_clarification',
		] as $kept) {
			self::assertContains($kept, $tools, $kept);
		}
		$output = json_decode($this->lastOpenAiRequest()['input'][0]['output'], true);
		self::assertFalse($output['ok']);
		self::assertStringContainsString('/create_tournament', $output['what_to_do']);
		self::assertSame('Создать турнир можно командой /create_tournament.', $this->http->lastText());
	}

	public function testListTournamentsSendsPrettyBlockAndShortText(): void {
		$t = $this->openTournament(2, '3,4');
		$this->app->teams->createTeam($t->id, $this->player('cap')->id, 'Wolves', [$this->player('mate')->id]);
		$vasya = $this->player('vasya');
		$this->http->queueOpenAi($this->functionCall('r1', 'c1', 'list_tournaments', []));
		$this->http->queueOpenAi($this->text('r2', 'Вот что есть 👇'));
		$this->dispatch($this->privateMessage($vasya, '@' . self::BOT_USERNAME . ' какие турниры есть ещё?'));

		$sent = $this->http->sent('sendMessage');
		self::assertCount(2, $sent);
		self::assertStringStartsWith('📋 <b>Турниры</b>', $sent[0]['text']);
		self::assertStringContainsString('👑 <a href="https://t.me/', $sent[0]['text']);
		self::assertStringContainsString('@cap</a>', $sent[0]['text']);
		self::assertStringContainsString('@mate</a>', $sent[0]['text']);
		self::assertSame('Вот что есть 👇', $sent[1]['text']);
		$output = json_decode($this->lastOpenAiRequest()['input'][0]['output'], true);
		self::assertTrue($output['show_list']);
		self::assertStringContainsString('Не пересказывай', $output['note']);

		$this->http->reset();
		$this->http->queueOpenAi($this->functionCall('r3', 'c3', 'list_tournaments', []));
		$this->http->queueOpenAi($this->text('r4', ''));
		$this->dispatch($this->privateMessage($vasya, '@' . self::BOT_USERNAME . ' покажи ещё раз турниры'));
		$sent = $this->http->sent('sendMessage');
		self::assertCount(1, $sent);
		self::assertStringStartsWith('📋 <b>Турниры</b>', $sent[0]['text']);
	}

	public function testToolsUseGlobalNumbers(): void {
		$later = $this->app->tournaments->create(new \P1Bot\Domain\CreateTournament(
			self::CHAT_ID,
			$this->owner->id,
			'Поздний',
			'2026-11-25 19:00',
			2,
			'3,3',
		));
		$this->app->tournaments->openRegistration($this->owner->id, $later->id); // id 1
		$this->openTournament(2, '3,3'); // id 2
		$vasya = $this->player('vasya');

		$this->http->queueOpenAi($this->functionCall('r1', 'c1', 'tournament_info', ['tournament_id' => 1]));
		$this->http->queueOpenAi($this->text('r2', 'Это «Поздний», №1.'));
		$this->dispatch($this->privateMessage($vasya, '@' . self::BOT_USERNAME . ' расскажи про турнир №1'));

		$output = json_decode($this->lastOpenAiRequest()['input'][0]['output'], true);
		self::assertTrue($output['ok']);
		self::assertSame(1, $output['tournament_id']);
		self::assertStringContainsString('Поздний', $output['info']);
		$context = $this->firstOpenAiRequest()['input'][0]['content'];
		self::assertStringContainsString('№1 Поздний', $context);
		self::assertStringContainsString('№2 Субботний кубок', $context);
		self::assertStringContainsString('Номера турниров общие', $context);
	}

	public function testListPlayersSendsThePlayersList(): void {
		$vasya = $this->player('vasya');
		$this->rate($vasya, 5);
		$this->http->queueOpenAi($this->functionCall('r1', 'c1', 'list_players', []));
		$this->http->queueOpenAi($this->text('r2', ''));
		$this->dispatch($this->privateMessage($vasya, '@' . self::BOT_USERNAME . ' покажи список игроков'));
		$sent = $this->http->sent('sendMessage');
		self::assertCount(1, $sent);
		self::assertStringStartsWith('📋 <b>Игроки</b>', $sent[0]['text']);
		self::assertStringContainsString('@vasya', $sent[0]['text']);
		self::assertStringContainsString('Как устроены уровни — /help_level', $sent[0]['text']);
		self::assertStringNotContainsString('✏️', $sent[0]['text']);
	}

	public function testHelpToolSendsHelp(): void {
		$vasya = $this->player('vasya');
		$this->http->queueOpenAi($this->functionCall('r1', 'c1', 'help', []));
		$this->http->queueOpenAi($this->text('r2', ''));
		$this->dispatch($this->privateMessage($vasya, '@' . self::BOT_USERNAME . ' что ты умеешь кроме команд?'));
		$sent = $this->http->sent('sendMessage');
		self::assertCount(1, $sent);
		self::assertStringContainsString('/tournaments — активные турниры', $sent[0]['text']);
		self::assertStringContainsString('/help_level — Как считаются уровни и сила команды', $sent[0]['text']);
	}

	public function testLevelsHelpIsSentByTheBot(): void {
		$this->http->queueOpenAi($this->functionCall('r1', 'c1', 'levels_help', []));
		$this->http->queueOpenAi($this->text('r2', ''));
		$this->dispatch($this->privateMessage(
			$this->player('vasya'),
			'@' . self::BOT_USERNAME . ' какие есть уровни?',
		));
		$sent = $this->http->sent('sendMessage');
		self::assertCount(1, $sent);
		self::assertStringStartsWith('📊 <b>Уровни игроков</b>', $sent[0]['text']);
	}

	public function testUnclearCardReplyGetsHintAndIsNotSentToAi(): void {
		self::assertNull($this->newDraft($this->owner, '23.11 20:00 | Кубок | 2 | 3,4'));
		$t = $this->app->tournament_repo->find(1);
		self::assertSame('draft', $t->status);
		$this->http->reset();
		$this->dispatch($this->privateMessage($this->owner, 'поправь время на 21:00', $this->replyTo($t->card_msg_id)));
		self::assertSame(MessageHandler::CARD_HINT, $this->http->lastText());
		self::assertSame('draft', $this->app->tournament_repo->find(1)->status);
		self::assertSame([], $this->openAiRequests());
	}

	public function testClearYesOnTheCardStillPublishesWithoutAi(): void {
		self::assertNull($this->newDraft($this->owner, '23.11 20:00 | Кубок | 2 | 3,4'));
		$t = $this->app->tournament_repo->find(1);
		$this->http->reset();
		$this->dispatch($this->privateMessage($this->owner, 'публикуем', $this->replyTo($t->card_msg_id)));
		self::assertSame('registration', $this->app->tournament_repo->find(1)->status);
		self::assertStringContainsString('Опубликовала!', $this->http->lastText());
		self::assertSame([], $this->openAiRequests());
	}

	public function testApiFailureGivesFriendlyReply(): void {
		$vasya = $this->player('vasya');
		$this->dispatch($this->privateMessage($vasya, '@' . self::BOT_USERNAME . ' привет'));
		self::assertSame('Что-то пошло не так, попробуй ещё раз чуть позже.', $this->http->lastText());
	}

	public function testPromptHasNoActionToolsAndUsesLvl(): void {
		$prompt = (string) file_get_contents(dirname(__DIR__, 2) . '/config/prompts/system.md');
		self::assertStringContainsString('lvl.N', $prompt);
		self::assertStringNotContainsString('сколько баллов из лимита', $prompt);
		self::assertStringNotContainsString('register_team', $prompt);
		self::assertStringNotContainsString('publish_tournament', $prompt);
		self::assertStringContainsString('/create_tournament', $prompt);
		self::assertStringNotContainsStringIgnoringCase('эталон', $prompt);
		self::assertStringNotContainsStringIgnoringCase('шкал', $prompt);
	}

	/**
		@return array<string, mixed>
	*/
	protected function replyTo(?int $message_id): array {
		return ['reply_to_message' => [
			'message_id' => $message_id,
			'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Bot'],
		]];
	}

	/**
		@return list<array<string, mixed>>
	*/
	protected function openAiRequests(): array {
		return array_values(array_map(
			static fn (array $r): array => $r['json'],
			array_filter($this->http->requests, static fn (array $r): bool => str_ends_with($r['url'], '/responses')),
		));
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
		@return array<string, mixed>
	*/
	protected function lastOpenAiRequest(): array {
		$requests = array_values(array_filter(
			$this->http->requests,
			static fn (array $r): bool => str_ends_with($r['url'], '/responses'),
		));
		return end($requests)['json'];
	}

	/**
		@return array<string, mixed>
	*/
	protected function firstOpenAiRequest(): array {
		foreach ($this->http->requests as $r) {
			if (str_ends_with($r['url'], '/responses')) {
				return $r['json'];
			}
		}
		return [];
	}

	protected function lastSentMessageId(): int {
		return 1000 + count($this->http->sent('sendMessage')) - 1;
	}
}
