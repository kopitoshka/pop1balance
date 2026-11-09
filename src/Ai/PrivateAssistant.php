<?php

declare(strict_types=1);

namespace P1Bot\Ai;

use P1Bot\Support\Logger;

/**
	Free text in a private chat. Read-only: the model gets the facts in the context and may only
	ask the bot to send the tournament list or the levels help. Nothing here changes data.
*/
class PrivateAssistant {
	protected const MAX_ROUNDS = 2;

	public function __construct(
		protected AiClient $ai,
		protected Logger $logger,
		protected string $system_prompt,
	) {
	}

	/**
		@return array{text: string, list: bool, levels: bool}
	*/
	public function reply(string $user_text, string $context): array {
		$tools = self::tools();
		$input = [
			['role' => 'developer', 'content' => $context],
			['role' => 'user', 'content' => $user_text !== '' ? $user_text : '(пустое сообщение)'],
		];
		$list = false;
		$levels = false;
		$response = $this->ai->createResponse($this->system_prompt, $input, $tools);
		for ($round = 0; $response->tool_calls !== [] && $round < self::MAX_ROUNDS; $round++) {
			$outputs = [];
			foreach ($response->tool_calls as $call) {
				if ($call['name'] === 'show_tournaments') {
					$list = true;
					$result = [
						'ok' => true,
						'note' => 'Список бот отправит сам. Не пересказывай его, ответь одной короткой фразой или ничего не добавляй.',
					];
				} elseif ($call['name'] === 'show_levels') {
					$levels = true;
					$result = [
						'ok' => true,
						'note' => 'Справку по уровням бот отправит сам. Не пересказывай её.',
					];
				} else {
					$result = [
						'ok' => false,
						'error' => 'Здесь можно только смотреть. Изменения делаются в группе турнира.',
					];
				}
				$this->logger->info('private tool ' . $call['name'], ['ok' => $result['ok']]);
				$outputs[] = [
					'type' => 'function_call_output',
					'call_id' => $call['call_id'],
					'output' => (string) json_encode($result, JSON_UNESCAPED_UNICODE),
				];
			}
			$response = $this->ai->createResponse($this->system_prompt, $outputs, $tools, $response->id);
		}
		return [
			'text' => $response->text,
			'list' => $list,
			'levels' => $levels,
		];
	}

	/**
		@return list<array<string, mixed>>
	*/
	public static function tools(): array {
		return [
			self::tool('show_tournaments', 'Отправить в личку красивый список активных турниров с составами.'),
			self::tool('show_levels', 'Отправить справку по уровням игроков и по уровню турнира.'),
		];
	}

	/**
		@return array<string, mixed>
	*/
	protected static function tool(string $name, string $description): array {
		return [
			'type' => 'function',
			'name' => $name,
			'description' => $description,
			'parameters' => [
				'type' => 'object',
				'properties' => (object) [],
				'additionalProperties' => false,
			],
			'strict' => false,
		];
	}
}
