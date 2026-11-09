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
	Turns a free-text message into a human reply: model + function calls executed in PHP.
	Dialog memory: a reply to the bot's answer continues the same conversation (TTL); in private
	any message within the TTL does.
*/
class Assistant {
	protected const MAX_ROUNDS = 5;
	protected const MAX_HISTORY = 12;

	public function __construct(
		protected AiClient $ai,
		protected Dispatcher $dispatcher,
		protected ConversationRepository $conversations,
		protected BotIdentity $bot,
		protected Clock $clock,
		protected Logger $logger,
		protected string $system_prompt,
		protected int $ttl_minutes = 30,
	) {
	}

	/**
		Produces the reply text and a callback to remember the dialog once the reply is sent.

		'card' is a draft id when the turn ended with a created/edited draft: the caller shows
		the card «Публикуем или что-то поправить?» instead of the model's text.

		'list' = the model asked for the tournament list: the caller sends the pretty list before the text.

		'groups' = the action needs a group choice (chat_id => title): the caller adds group buttons.

		@return array{text: string, remember: callable(int): void, card: ?int, history: list<array<string, mixed>>, list: bool, levels: bool, groups: array<int, string>}
	*/
	public function reply(Context $ctx): array {
		$message = $ctx->message;
		$conversation = null;
		if ($message->reply_to_message_id !== null && $message->isReplyToUser($this->bot->bot_id)) {
			$found = $this->conversations->findByBotMessage(
				$ctx->chatId(),
				$message->reply_to_message_id,
				$this->clock->now(),
			);
			if ($found !== null && $found['player_id'] === $ctx->actor->id) {
				$conversation = $found;
			}
		}
		if ($conversation === null && $ctx->isPrivate()) {
			$conversation = $this->conversations->findLatest($ctx->chatId(), $ctx->actor->id, $this->clock->now());
		}
		$history = $conversation['history'] ?? [];
		$user_text = $message->textWithoutMention($this->bot->username);
		if ($user_text === '') {
			$user_text = '(пустое сообщение, просто упомянули бота)';
		}

		$input = array_merge(
			[['role' => 'developer', 'content' => $this->contextBlock($ctx)]],
			$history,
			[['role' => 'user', 'content' => $user_text]],
		);
		$tools = Tools::definitions($ctx->is_admin, $ctx->is_owner);
		$allowed = Tools::names($ctx->is_admin, $ctx->is_owner);

		$card = null;
		$list = false;
		$levels = false;
		$groups = [];
		$response = $this->ai->createResponse($this->system_prompt, $input, $tools);
		for ($round = 0; $response->tool_calls !== [] && $round < self::MAX_ROUNDS; $round++) {
			$outputs = [];
			$card = null;
			foreach ($response->tool_calls as $call) {
				$result = in_array($call['name'], $allowed, true)
					? $this->dispatcher->dispatch($call['name'], $call['arguments'], $ctx)
					: ['ok' => false, 'error' => 'Это действие недоступно.'];
				$this->logger->info(
					'tool ' . $call['name'],
					['args' => $call['arguments'], 'ok' => $result['ok'] ?? false],
				);
				if (!empty($result['show_list'])) {
					$list = true;
				}
				if (!empty($result['show_levels'])) {
					$levels = true;
				}
				if (!empty($result['choose_group_ids'])) {
					$groups = (array) $result['choose_group_ids'];
					unset($result['choose_group_ids']);
				}
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
			$response = $this->ai->createResponse($this->system_prompt, $outputs, $tools, $response->id);
		}

		if ($card === null && $response->text !== '') {
			$text = $response->text;
		} else {
			$text = ($list || $levels) && $card === null ? '' : 'Готово!'; // the list / help speaks for itself
		}
		$new_history = array_slice(array_merge($history, [
			['role' => 'user', 'content' => $user_text],
			['role' => 'assistant', 'content' => $text],
		]), -self::MAX_HISTORY);
		$conversation_id = $conversation['id'] ?? null;

		$remember = function (int $bot_message_id) use ($ctx, $new_history, $conversation_id): void {
			$this->conversations->save(
				$conversation_id,
				$ctx->chatId(),
				$ctx->actor->id,
				$bot_message_id,
				$new_history,
				$this->clock->now()->modify("+{$this->ttl_minutes} minutes"),
			);
		};

		return [
			'text' => $text,
			'remember' => $remember,
			'card' => $card,
			'list' => $list,
			'levels' => $levels,
			'groups' => $groups,
			'history' => array_slice(
				array_merge($history, [['role' => 'user', 'content' => $user_text]]),
				-self::MAX_HISTORY,
			),
		];
	}

	/**
		Facts the model needs; it is rebuilt for every message.
	*/
	protected function contextBlock(Context $ctx): string {
		$author = $ctx->actor;
		$tournaments = $this->dispatcher->dispatch('list_tournaments', [], $ctx)['tournaments'] ?? [];
		$status = $this->dispatcher->statusLines($ctx);
		$drafts = $this->dispatcher->draftLines($ctx);

		return implode("\n", [
			'Контекст (для тебя, не пересказывай его целиком):',
			'Сейчас: ' . MoscowTime::format($this->clock->now()) . ', ' . self::weekday($this->clock->now()),
			sprintf(
				'Автор сообщения: %s%s.',
				$author->display_name !== '' ? $author->display_name : 'игрок',
				$author->username !== null ? ' (@' . $author->username . ')' : '',
			),
			'Автор — ' . self::role($ctx),
			$ctx->isPrivate()
				? 'Разговор в личке. Номера турниров общие для всех групп (№N = id турнира).'
					. ($ctx->group_title !== '' ? ' Текущая группа для создания: ' . $ctx->group_title . '.' : '')
				: 'Разговор в группе.',
			'Активные турниры: ' . ($tournaments === [] ? 'нет.' : "\n- " . implode("\n- ", $tournaments)),
			'Заявки автора: ' . implode('; ', $status),
			'Неопубликованные черновики автора: ' . ($drafts === [] ? 'нет.' : "\n- " . implode("\n- ", $drafts)),
		]);
	}

	protected static function role(Context $ctx): string {
		if ($ctx->is_owner) {
			return 'главный админ: ему доступны все действия, только он меняет уровни игроков.';
		}
		if ($ctx->is_admin) {
			return 'может создавать турниры (есть общая группа с организатором P1); править/открывать/закрывать/отменять — только свои (или главный админ — любые). Уровни игроков меняет только главный админ.';
		}
		return 'обычный игрок: создавать турниры нельзя, записаться и собирать команду можно.';
	}

	protected static function weekday(\DateTimeImmutable $utc): string {
		$days = ['воскресенье', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота'];
		return $days[(int) $utc->setTimezone(MoscowTime::zone())->format('w')];
	}
}
