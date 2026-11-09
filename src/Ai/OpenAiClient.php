<?php

declare(strict_types=1);

namespace P1Bot\Ai;

use P1Bot\Http\HttpClient;
use P1Bot\Support\Logger;
use P1Bot\Support\Redact;
use Throwable;

/**
	OpenAI Responses API: POST /v1/responses with function tools.
*/
class OpenAiClient implements AiClient {

	public function __construct(
		protected HttpClient $http,
		protected string $api_key,
		protected string $model = 'gpt-6-luna',
		protected string $reasoning_effort = 'low',
		protected string $base_url = 'https://api.openai.com/v1',
		protected int $timeout = 30,
		protected ?Logger $logger = null,
	) {
	}

	public function createResponse(
		string $instructions,
		array $input,
		array $tools,
		?string $previous_response_id = null,
	): AiResponse {
		$payload = [
			'model' => $this->model,
			'instructions' => $instructions,
			'input' => $input,
			'tools' => $tools,
			'tool_choice' => 'auto',
			'parallel_tool_calls' => true,
			'reasoning' => ['effort' => $this->reasoning_effort],
			'max_output_tokens' => 1500,
		];
		if ($previous_response_id !== null) {
			$payload['previous_response_id'] = $previous_response_id;
		}

		$this->logger?->info(sprintf('ai request start: model=%s effort=%s %s', $this->model, $this->reasoning_effort,
			$previous_response_id !== null ? 'continues ' . $previous_response_id : 'new conversation'));
		$started = microtime(true);
		try {
			$response = $this->http->postJson(
				rtrim($this->base_url, '/') . '/responses',
				$payload,
				['Authorization' => 'Bearer ' . $this->api_key],
				$this->timeout,
			);
		} catch (Throwable $e) {
			$this->logger?->error(sprintf(
				'ai request failed after %dms: %s',
				$this->elapsedMs($started),
				Redact::text($e->getMessage(), [$this->api_key]),
			));
			throw $e;
		}
		$elapsed = $this->elapsedMs($started);
		$body = json_decode($response->body, true);
		if ($response->status !== 200 || !is_array($body)) {
			$message = is_array($body) ? (string) ($body['error']['message'] ?? 'unknown error') : 'invalid response';
			$this->logger?->error(sprintf(
				'ai response HTTP %d after %dms, model=%s: %s',
				$response->status,
				$elapsed,
				$this->model,
				Redact::text($response->body, [$this->api_key], 500),
			));
			throw new AiError(sprintf('OpenAI HTTP %d: %s', $response->status, $message));
		}
		$this->logger?->info(sprintf('ai response HTTP 200 after %dms, model=%s', $elapsed, $this->model));
		return AiResponse::fromApi($body);
	}

	protected function elapsedMs(float $started): int {
		return (int) round((microtime(true) - $started) * 1000);
	}
}
