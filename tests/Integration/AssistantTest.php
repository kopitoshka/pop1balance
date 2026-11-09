<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Tests\Support\IntegrationTestCase;

final class AssistantTest extends IntegrationTestCase
{
    protected function config(): array
    {
        $config = parent::config();
        $config['openai']['api_key'] = 'sk-test';
        $config['paths'] = ['prompt' => dirname(__DIR__, 2) . '/config/prompts/system.md'];
        return $config;
    }

    public function testRegisterTeamThroughFunctionCall(): void
    {
        $t = $this->openTournament(3, '3,3,4');
        $cap = $this->player('cap');
        $this->player('vasya');
        $this->http->queueOpenAi($this->functionCall('resp_1', 'call_1', 'register_team', [
            'tournament_id' => $t->id, 'team_name' => 'Wolves', 'players' => ['@vasya', '@petya'],
        ]));
        $this->http->queueOpenAi($this->text('resp_2', 'Записал Wolves: ты, @vasya и @petya. Вы в основном составе <3'));

        $update = $this->groupMessage($cap, '@' . self::BOT_USERNAME . ' запиши нас Wolves с @vasya и @petya');
        $this->dispatch($update);

        $entry = $this->app->teams->findByName($t->id, 'Wolves');
        self::assertNotNull($entry);
        self::assertTrue($entry->team->isRegistered());
        self::assertSame($cap->id, $entry->team->captainId);

        $openAi = array_values(array_filter($this->http->requests, static fn (array $r): bool => str_ends_with($r['url'], '/responses')));
        self::assertCount(2, $openAi);
        $first = $openAi[0];
        self::assertSame('Bearer sk-test', $first['headers']['Authorization']);
        self::assertSame('gpt-6-luna', $first['json']['model']);
        self::assertSame(['effort' => 'low'], $first['json']['reasoning']);
        self::assertStringContainsString('Population: One', $first['json']['instructions']);
        self::assertStringContainsString('@' . self::BOT_USERNAME, $first['json']['instructions']);
        self::assertSame('developer', $first['json']['input'][0]['role']);
        self::assertStringContainsString('№' . $t->id . ' Субботний кубок', $first['json']['input'][0]['content']);
        self::assertSame(['role' => 'user', 'content' => 'запиши нас Wolves с @vasya и @petya'], $first['json']['input'][1]);
        $toolNames = array_column($first['json']['tools'], 'name');
        self::assertContains('register_team', $toolNames);
        self::assertNotContains('create_tournament', $toolNames, 'admin tools hidden from players');

        $second = $openAi[1]['json'];
        self::assertSame('resp_1', $second['previous_response_id']);
        self::assertSame('function_call_output', $second['input'][0]['type']);
        self::assertSame('call_1', $second['input'][0]['call_id']);
        $output = json_decode($second['input'][0]['output'], true);
        self::assertTrue($output['ok']);
        self::assertSame('основной состав', $output['placement']);

        $reply = $this->http->sent('sendMessage');
        $last = end($reply);
        self::assertSame('Записал Wolves: ты, @vasya и @petya. Вы в основном составе &lt;3', $last['text']);
        self::assertSame($update['message']['message_id'], $last['reply_parameters']['message_id']);
    }

    public function testReplyContinuesDialogAndExpires(): void
    {
        $this->openTournament();
        $vasya = $this->player('vasya');
        $this->http->queueOpenAi($this->text('r1', 'На какой турнир тебя записать?'));
        $this->dispatch($this->groupMessage($vasya, '@' . self::BOT_USERNAME . ' хочу играть'));
        $botMessageId = $this->lastSentMessageId();

        $this->http->queueOpenAi($this->text('r2', 'Записал!'));
        $this->dispatch($this->groupMessage($vasya, 'на субботний', ['reply_to_message' => [
            'message_id' => $botMessageId, 'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Bot'],
        ]]));
        $input = $this->lastOpenAiRequest()['input'];
        self::assertSame(['role' => 'user', 'content' => 'хочу играть'], $input[1]);
        self::assertSame(['role' => 'assistant', 'content' => 'На какой турнир тебя записать?'], $input[2]);
        self::assertSame(['role' => 'user', 'content' => 'на субботний'], $input[3]);

        // Another player replying to the same bot message starts fresh.
        $botMessageId = $this->lastSentMessageId();
        $this->http->queueOpenAi($this->text('r3', 'Привет!'));
        $this->dispatch($this->groupMessage($this->player('petya'), 'а я?', ['reply_to_message' => [
            'message_id' => $botMessageId, 'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Bot'],
        ]]));
        self::assertCount(2, $this->lastOpenAiRequest()['input']);

        // After the TTL the dialog is forgotten ($botMessageId is still vasya's last bot reply).
        $this->clock->advance('+31 minutes');
        $this->http->queueOpenAi($this->text('r4', 'Ок'));
        $this->dispatch($this->groupMessage($vasya, 'и ещё', ['reply_to_message' => [
            'message_id' => $botMessageId, 'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Bot'],
        ]]));
        self::assertCount(2, $this->lastOpenAiRequest()['input']);
    }

    public function testPlayerCannotCallAdminToolEvenIfModelTries(): void
    {
        $vasya = $this->player('vasya');
        $this->http->queueOpenAi($this->functionCall('r1', 'c1', 'create_tournament', [
            'title' => 'Хак', 'starts_at_msk' => '23.11 20:00', 'team_size' => 2,
        ]));
        $this->http->queueOpenAi($this->text('r2', 'Создавать турниры могут только админы.'));
        $this->dispatch($this->groupMessage($vasya, '@' . self::BOT_USERNAME . ' создай турнир'));

        self::assertNull($this->app->tournamentRepo->find(1));
        $output = json_decode($this->lastOpenAiRequest()['input'][0]['output'], true);
        self::assertFalse($output['ok']);
    }

    public function testAdminCreatesAndOpensTournament(): void
    {
        $this->http->queueOpenAi($this->functionCall('r1', 'c1', 'create_tournament', [
            'title' => 'Ночной кубок', 'starts_at_msk' => '23.11 22:00', 'team_size' => 2, 'reference_team' => '4,4', 'open_registration' => true,
        ]));
        $this->http->queueOpenAi($this->text('r2', 'Готово, анонс в чате!'));
        $this->dispatch($this->groupMessage($this->owner, '@' . self::BOT_USERNAME . ' создай ночной кубок на 23 ноября в 22, пары, эталон 4,4 и открой регистрацию'));

        $t = $this->app->tournamentRepo->find(1);
        self::assertNotNull($t);
        self::assertSame('registration', $t->status);
        self::assertSame(8, $t->maxPoints);
        self::assertNotNull($t->announceMsgId);
        self::assertContains('create_tournament', array_column($this->firstOpenAiRequest()['tools'], 'name'));
    }

    public function testLevelLimitErrorGoesBackToModel(): void
    {
        $t = $this->openTournament(2, '3,3');
        $cap = $this->player('cap');
        $this->rate($cap, 5);
        $this->player('pro');
        $this->rate($this->app->players->findByUsername('pro'), 5);
        $this->http->queueOpenAi($this->functionCall('r1', 'c1', 'register_team', ['team_name' => 'Pros', 'players' => ['@pro']]));
        $this->http->queueOpenAi($this->text('r2', 'Не выйдет: перебор на 4 балла.'));
        $this->dispatch($this->groupMessage($cap, '@' . self::BOT_USERNAME . ' запиши Pros со @pro'));

        $output = json_decode($this->lastOpenAiRequest()['input'][0]['output'], true);
        self::assertFalse($output['ok']);
        self::assertStringContainsString('перебор на 4 балла', $output['error']);
        self::assertNull($this->app->teams->findByName($t->id, 'Pros'));
    }

    public function testApiFailureGivesFriendlyReply(): void
    {
        $vasya = $this->player('vasya');
        // nothing queued => fake returns HTTP 500
        $this->dispatch($this->groupMessage($vasya, '@' . self::BOT_USERNAME . ' привет'));
        self::assertSame('Что-то пошло не так, попробуй ещё раз чуть позже.', $this->http->lastText());
    }

    /** @return array<string, mixed> */
    private function functionCall(string $id, string $callId, string $name, array $args): array
    {
        return ['id' => $id, 'output' => [
            ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []],
            ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => $callId, 'name' => $name, 'arguments' => json_encode($args, JSON_UNESCAPED_UNICODE)],
        ]];
    }

    /** @return array<string, mixed> */
    private function text(string $id, string $text): array
    {
        return ['id' => $id, 'output' => [[
            'type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]],
        ]]];
    }

    /** @return array<string, mixed> */
    private function lastOpenAiRequest(): array
    {
        $requests = array_values(array_filter($this->http->requests, static fn (array $r): bool => str_ends_with($r['url'], '/responses')));
        return end($requests)['json'];
    }

    /** @return array<string, mixed> */
    private function firstOpenAiRequest(): array
    {
        foreach ($this->http->requests as $r) {
            if (str_ends_with($r['url'], '/responses')) {
                return $r['json'];
            }
        }
        return [];
    }

    private function lastSentMessageId(): int
    {
        return 1000 + count($this->http->sent('sendMessage')) - 1;
    }
}
