<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

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
		$this->http->queueOpenAi($this->text('r1', 'На какой турнир тебя записать?'));
		$this->dispatch($this->privateMessage($vasya, '@' . self::BOT_USERNAME . ' хочу играть'));
		$bot_message_id = $this->lastSentMessageId();

		$this->http->queueOpenAi($this->text('r2', 'Записала!'));
		$this->dispatch($this->privateMessage($vasya, 'на субботний', ['reply_to_message' => [
			'message_id' => $bot_message_id,
			'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Bot'],
		]]));
		$input = $this->lastOpenAiRequest()['input'];
		self::assertSame(['role' => 'user', 'content' => 'хочу играть'], $input[1]);
		self::assertSame(['role' => 'assistant', 'content' => 'На какой турнир тебя записать?'], $input[2]);
		self::assertSame(['role' => 'user', 'content' => 'на субботний'], $input[3]);

		// Another player replying to the same bot message starts fresh.
		$bot_message_id = $this->lastSentMessageId();
		$this->http->queueOpenAi($this->text('r3', 'Привет!'));
		$this->dispatch($this->privateMessage($this->player('petya'), 'а я?', ['reply_to_message' => [
			'message_id' => $bot_message_id,
			'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Bot'],
		]]));
		self::assertCount(2, $this->lastOpenAiRequest()['input']);

		// After the TTL the dialog is forgotten ($bot_message_id is still vasya's last bot reply).
		$this->clock->advance('+31 minutes');
		$this->http->queueOpenAi($this->text('r4', 'Ок'));
		$this->dispatch($this->privateMessage($vasya, 'и ещё', ['reply_to_message' => [
			'message_id' => $bot_message_id,
			'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Bot'],
		]]));
		self::assertCount(2, $this->lastOpenAiRequest()['input']);
	}

	public function testPlayerCannotCallAdminToolEvenIfModelTries(): void {
		$vasya = $this->player('vasya');
		$this->http->queueOpenAi($this->functionCall('r1', 'c1', 'create_tournament', [
			'title' => 'Хак',
			'starts_at_msk' => '23.11 20:00',
			'team_size' => 2,
		]));
		$this->http->queueOpenAi($this->text('r2', 'Создавать турниры могут только участники групп, где есть организатор P1.'));
		$this->dispatch($this->privateMessage($vasya, '@' . self::BOT_USERNAME . ' создай турнир'));

		self::assertNull($this->app->tournament_repo->find(1));
		$output = json_decode($this->lastOpenAiRequest()['input'][0]['output'], true);
		self::assertFalse($output['ok']);
	}

	public function testAdminCreatesDraftAndGetsCardInsteadOfPublishing(): void {
		$this->http->queueOpenAi($this->functionCall('r1', 'c1', 'create_tournament', [
			'title' => 'Ночной кубок',
			'starts_at_msk' => '23.11 22:00',
			'team_size' => 2,
			'level' => 'танк, средний+',
			'open_registration' => true, // old parameter is ignored
		]));
		$this->dispatch($this->privateMessage(
			$this->owner,
			'@' . self::BOT_USERNAME . ' создай ночной кубок на 23 ноября в 22, пары, уровень танк и средний+, и сразу публикуй',
		));

		$t = $this->app->tournament_repo->find(1);
		self::assertSame('draft', $t->status, 'never published without confirming the card');
		self::assertSame(10, $t->max_points);
		self::assertNull($t->announce_msg_id);
		$card = $this->http->sent('sendMessage');
		self::assertCount(1, $card);
		self::assertStringEndsWith('Публикуем или что-то поправить?', $card[0]['text']);
		self::assertSame('pub:1', $card[0]['reply_markup']['inline_keyboard'][0][0]['callback_data']);
		self::assertSame($t->card_msg_id, 1000);
		self::assertCount(1, $this->openAiRequests(), 'the card ends the turn, no extra model round');

		$tools = array_column($this->firstOpenAiRequest()['tools'], null, 'name');
		self::assertContains('level', $tools['create_tournament']['parameters']['required']);
		$request = $this->firstOpenAiRequest();
		self::assertStringNotContainsStringIgnoringCase(
			'эталон',
			$request['instructions'] . json_encode($request['tools'], JSON_UNESCAPED_UNICODE),
		);
		self::assertArrayNotHasKey(
			'open_registration',
			(array) $tools['create_tournament']['parameters']['properties'],
		);
		self::assertArrayNotHasKey('remove_level_limit', (array) $tools['edit_tournament']['parameters']['properties']);
		self::assertArrayHasKey('publish_tournament', $tools);
	}

	public function testCorrectionThenClearYesPublishes(): void {
		$this->http->queueOpenAi($this->functionCall('r1', 'c1', 'create_tournament', [
			'title' => 'Кубок',
			'starts_at_msk' => '23.11 20:00',
			'team_size' => 3,
			'reference_team' => 'средний, средний, средний+',
		]));
		$this->dispatch($this->privateMessage(
			$this->owner,
			'@' . self::BOT_USERNAME . ' создай кубок 23.11 в 20, тройки, средний средний средний+',
		));
		$first_card = $this->app->tournament_repo->find(1)->card_msg_id;

		// A correction in reply to the card goes to the model, which edits the draft: new card, still a draft.
		$this->http->queueOpenAi($this->functionCall(
			'r2',
			'c2',
			'edit_tournament',
			['tournament_id' => 1, 'starts_at_msk' => '23.11 21:00'],
		));
		$this->dispatch($this->privateMessage($this->owner, 'ок, но время 21:00', $this->replyTo($first_card)));
		$t = $this->app->tournament_repo->find(1);
		self::assertSame('draft', $t->status);
		self::assertSame('2026-11-23 18:00:00', \P1Bot\Support\DateFormat::toDb($t->starts_at));
		self::assertNotSame($first_card, $t->card_msg_id);
		$input = $this->lastOpenAiRequest()['input'];
		self::assertStringContainsString(
			'Публикуем или что-то поправить?',
			$input[count($input) - 2]['content'],
			'the card is in the dialog history',
		);

		// A clear yes to the fresh card publishes without asking the model.
		$before = count($this->openAiRequests());
		$this->dispatch($this->privateMessage($this->owner, 'Публикуем!', $this->replyTo($t->card_msg_id)));
		self::assertSame('registration', $this->app->tournament_repo->find(1)->status);
		self::assertCount($before, $this->openAiRequests());
	}

	public function testModelCannotPublishOnUnclearAnswer(): void {
		$this->http->queueOpenAi($this->functionCall('r1', 'c1', 'create_tournament', [
			'title' => 'Кубок',
			'starts_at_msk' => '23.11 20:00',
			'team_size' => 2,
			'reference_team' => '3,4',
		]));
		$this->dispatch($this->privateMessage(
			$this->owner,
			'@' . self::BOT_USERNAME . ' создай кубок 23.11 в 20, пары, 3 и 4',
		));
		$card = $this->app->tournament_repo->find(1)->card_msg_id;

		// The model wrongly tries to publish and to open registration on an unclear answer.
		$this->http->queueOpenAi($this->functionCall('r2', 'c2', 'open_registration', ['tournament_id' => 1]));
		$this->http->queueOpenAi($this->functionCall('r3', 'c3', 'publish_tournament', ['tournament_id' => 1]));
		$this->dispatch($this->privateMessage($this->owner, 'ну наверное можно', $this->replyTo($card)));

		$t = $this->app->tournament_repo->find(1);
		self::assertSame('draft', $t->status);
		self::assertNull($t->announce_msg_id);
		$outputs = array_column(
			array_filter($this->openAiRequests(), static fn (array $r): bool => isset($r['input'][0]['output'])),
			'input',
		);
		self::assertCount(0, $outputs, 'open_registration on a draft only shows the card and ends the turn');
		self::assertStringEndsWith(
			'Публикуем или что-то поправить?',
			$this->http->lastText(),
			'publish refused, card shown again',
		);
		self::assertNotSame($card, $t->card_msg_id);
	}

	public function testModelPublishesAfterClearYes(): void {
		$this->http->queueOpenAi($this->functionCall('r1', 'c1', 'create_tournament', [
			'title' => 'Кубок',
			'starts_at_msk' => '23.11 20:00',
			'team_size' => 2,
			'reference_team' => '3,4',
		]));
		$this->dispatch($this->privateMessage(
			$this->owner,
			'@' . self::BOT_USERNAME . ' создай кубок 23.11 в 20, пары, 3 и 4',
		));

		$this->http->queueOpenAi($this->functionCall('r2', 'c2', 'publish_tournament', ['tournament_id' => 1]));
		$this->http->queueOpenAi($this->text('r3', 'Опубликовала, анонс в чате!'));
		$this->dispatch($this->privateMessage($this->owner, '@' . self::BOT_USERNAME . ' да, публикуем'));

		$t = $this->app->tournament_repo->find(1);
		self::assertSame('registration', $t->status);
		self::assertNotNull($t->announce_msg_id);
		self::assertSame('Опубликовала, анонс в чате!', $this->http->lastText());
	}

	public function testListTournamentsSendsPrettyBlockAndShortText(): void {
		$t = $this->openTournament(2, '3,4');
		$this->app->teams->createTeam($t->id, $this->player('cap')->id, 'Wolves', [$this->player('mate')->id]);
		$vasya = $this->player('vasya');
		$this->http->queueOpenAi($this->functionCall('r1', 'c1', 'list_tournaments', []));
		$this->http->queueOpenAi($this->text('r2', 'Вот что есть 👇'));
		$this->dispatch($this->privateMessage($vasya, '@' . self::BOT_USERNAME . ' какие турниры?'));

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

		// The model may add nothing: then only the list is sent.
		$this->http->reset();
		$this->http->queueOpenAi($this->functionCall('r3', 'c3', 'list_tournaments', []));
		$this->http->queueOpenAi($this->text('r4', ''));
		$this->dispatch($this->privateMessage($vasya, '@' . self::BOT_USERNAME . ' турниры'));
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
		$earlier = $this->openTournament(2, '3,3'); // id 2
		$vasya = $this->player('vasya');

		$this->http->queueOpenAi($this->functionCall('r1', 'c1', 'join_solo', ['tournament_id' => 1]));
		$this->http->queueOpenAi($this->text('r2', 'Записала!'));
		$this->dispatch($this->privateMessage($vasya, '@' . self::BOT_USERNAME . ' запиши меня соло на №1'));

		self::assertSame('main', $this->app->tournaments->lineup($later->id)->placementOf($vasya->id));
		self::assertNull($this->app->tournaments->lineup($earlier->id)->placementOf($vasya->id));
		$output = json_decode($this->lastOpenAiRequest()['input'][0]['output'], true);
		self::assertSame(1, $output['tournament_id']);
		$context = $this->firstOpenAiRequest()['input'][0]['content'];
		self::assertStringContainsString('№1 Поздний', $context);
		self::assertStringContainsString('№2 Субботний кубок', $context);
		self::assertStringContainsString('Номера турниров общие', $context);
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

	public function testApiFailureGivesFriendlyReply(): void {
		$vasya = $this->player('vasya');
		// nothing queued => fake returns HTTP 500
		$this->dispatch($this->privateMessage($vasya, '@' . self::BOT_USERNAME . ' привет'));
		self::assertSame('Что-то пошло не так, попробуй ещё раз чуть позже.', $this->http->lastText());
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

	public function testTotalLevelThroughAiAndInvalidLevelAsksAgain(): void {
		$this->http->queueOpenAi($this->functionCall('r1', 'c1', 'create_tournament', [
			'title' => 'Кубок',
			'starts_at_msk' => '23.11 20:00',
			'team_size' => 3,
			'level' => 'средний',
		]));
		$this->http->queueOpenAi($this->text('r2', 'Уточни уровень: сумма баллов или уровни всех трёх игроков?'));
		$this->dispatch($this->privateMessage(
			$this->owner,
			'@' . self::BOT_USERNAME . ' создай кубок 23.11 в 20, тройки, уровень средний',
		));
		self::assertNull($this->app->tournament_repo->find(1));
		$output = json_decode($this->lastOpenAiRequest()['input'][0]['output'], true);
		self::assertFalse($output['ok']);
		self::assertStringContainsString('уровней указано 1', $output['error']);
		self::assertStringContainsString('Переспроси уровень', $output['what_to_do']);

		$this->http->queueOpenAi($this->functionCall('r3', 'c3', 'create_tournament', [
			'title' => 'Кубок',
			'starts_at_msk' => '23.11 20:00',
			'team_size' => 3,
			'level' => 10,
		]));
		$this->dispatch($this->privateMessage($this->owner, '@' . self::BOT_USERNAME . ' макс. уровень 10'));
		$t = $this->app->tournament_repo->find(1);
		self::assertSame(10, $t->max_points);
		self::assertStringContainsString('Уровень турнира: средний (10 баллов на троих)', $this->http->lastText());
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
}
