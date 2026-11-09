<?php

declare(strict_types=1);

namespace P1Bot\Ai;

use P1Bot\Domain\MoscowTime;
use P1Bot\Handlers\Context;
use P1Bot\Repository\ConversationRepository;
use P1Bot\Support\Clock;
use P1Bot\Support\Logger;
use P1Bot\Telegram\BotIdentity;

/**
 * Turns a free-text message into a human reply: model + function calls executed in PHP.
 * Dialog memory: a reply to the bot's answer continues the same conversation (TTL).
 */
final class Assistant
{
    private const MAX_ROUNDS = 5;
    private const MAX_HISTORY = 12;

    public function __construct(
        private AiClient $ai,
        private Dispatcher $dispatcher,
        private ConversationRepository $conversations,
        private BotIdentity $bot,
        private Clock $clock,
        private Logger $logger,
        private string $systemPrompt,
        private int $ttlMinutes = 30,
    ) {
    }

    /**
     * Produces the reply text and a callback to remember the dialog once the reply is sent.
     *
     * 'card' is a draft id when the turn ended with a created/edited draft: the caller shows
     * the card «Публикуем или что-то поправить?» instead of the model's text.
     *
     * @return array{text: string, remember: callable(int): void, card: ?int, history: list<array<string, mixed>>}
     */
    public function reply(Context $ctx): array
    {
        $message = $ctx->message;
        $conversation = null;
        if ($message->replyToMessageId !== null && $message->isReplyToUser($this->bot->botId)) {
            $found = $this->conversations->findByBotMessage($ctx->chatId(), $message->replyToMessageId, $this->clock->now());
            if ($found !== null && $found['player_id'] === $ctx->actor->id) {
                $conversation = $found;
            }
        }
        $history = $conversation['history'] ?? [];
        $userText = $message->textWithoutMention($this->bot->username);
        if ($userText === '') {
            $userText = '(пустое сообщение, просто упомянули бота)';
        }

        $input = array_merge(
            [['role' => 'developer', 'content' => $this->contextBlock($ctx)]],
            $history,
            [['role' => 'user', 'content' => $userText]],
        );
        $tools = Tools::definitions($ctx->isAdmin);
        $allowed = Tools::names($ctx->isAdmin);

        $card = null;
        $response = $this->ai->createResponse($this->systemPrompt, $input, $tools);
        for ($round = 0; $response->toolCalls !== [] && $round < self::MAX_ROUNDS; $round++) {
            $outputs = [];
            $card = null;
            foreach ($response->toolCalls as $call) {
                $result = in_array($call['name'], $allowed, true)
                    ? $this->dispatcher->dispatch($call['name'], $call['arguments'], $ctx)
                    : ['ok' => false, 'error' => 'Это действие недоступно.'];
                $this->logger->info('tool ' . $call['name'], ['args' => $call['arguments'], 'ok' => $result['ok'] ?? false]);
                if (isset($result['show_card'])) {
                    $card = (int) $result['show_card'];
                } elseif (!empty($result['published'])) {
                    $card = null;
                }
                $outputs[] = [
                    'type' => 'function_call_output',
                    'call_id' => $call['call_id'],
                    'output' => (string) json_encode($result, JSON_UNESCAPED_UNICODE),
                ];
            }
            if ($card !== null) {
                break; // the card is the answer; no need for another model round
            }
            $response = $this->ai->createResponse($this->systemPrompt, $outputs, $tools, $response->id);
        }

        $text = $card === null && $response->text !== '' ? $response->text : 'Готово!';
        $newHistory = array_slice(array_merge($history, [
            ['role' => 'user', 'content' => $userText],
            ['role' => 'assistant', 'content' => $text],
        ]), -self::MAX_HISTORY);
        $conversationId = $conversation['id'] ?? null;

        $remember = function (int $botMessageId) use ($ctx, $newHistory, $conversationId): void {
            $this->conversations->save(
                $conversationId,
                $ctx->chatId(),
                $ctx->actor->id,
                $botMessageId,
                $newHistory,
                $this->clock->now()->modify("+{$this->ttlMinutes} minutes"),
            );
        };

        return [
            'text' => $text,
            'remember' => $remember,
            'card' => $card,
            'history' => array_slice(array_merge($history, [['role' => 'user', 'content' => $userText]]), -self::MAX_HISTORY),
        ];
    }

    /** Facts the model needs; it is rebuilt for every message. */
    private function contextBlock(Context $ctx): string
    {
        $author = $ctx->actor;
        $tournaments = $this->dispatcher->dispatch('list_tournaments', [], $ctx)['tournaments'] ?? [];
        $status = $this->dispatcher->statusLines($ctx);
        $drafts = $this->dispatcher->draftLines($ctx);

        return implode("\n", [
            'Контекст (для тебя, не пересказывай его целиком):',
            'Сейчас: ' . MoscowTime::format($this->clock->now()) . ', ' . self::weekday($this->clock->now()),
            sprintf('Автор сообщения: %s%s.', $author->displayName !== '' ? $author->displayName : 'игрок', $author->username !== null ? ' (@' . $author->username . ')' : ''),
            'Автор — ' . ($ctx->isAdmin ? 'админ чата: ему доступны действия админа.' : 'обычный игрок: действия админа ему недоступны.'),
            'Активные турниры: ' . ($tournaments === [] ? 'нет.' : "\n- " . implode("\n- ", $tournaments)),
            'Заявки автора: ' . implode('; ', $status),
            'Неопубликованные черновики автора: ' . ($drafts === [] ? 'нет.' : "\n- " . implode("\n- ", $drafts)),
        ]);
    }

    private static function weekday(\DateTimeImmutable $utc): string
    {
        $days = ['воскресенье', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота'];
        return $days[(int) $utc->setTimezone(MoscowTime::zone())->format('w')];
    }
}
