<?php

declare(strict_types=1);

namespace P1Bot\Ai;

use P1Bot\Http\HttpClient;
use P1Bot\Support\Logger;
use P1Bot\Support\Redact;
use Throwable;

/** OpenAI Responses API: POST /v1/responses with function tools. */
final class OpenAiClient implements AiClient
{
    public function __construct(
        private HttpClient $http,
        private string $apiKey,
        private string $model = 'gpt-6-luna',
        private string $reasoningEffort = 'low',
        private string $baseUrl = 'https://api.openai.com/v1',
        private int $timeout = 30,
        private ?Logger $logger = null,
    ) {
    }

    public function createResponse(string $instructions, array $input, array $tools, ?string $previousResponseId = null): AiResponse
    {
        $payload = [
            'model' => $this->model,
            'instructions' => $instructions,
            'input' => $input,
            'tools' => $tools,
            'tool_choice' => 'auto',
            'parallel_tool_calls' => true,
            'reasoning' => ['effort' => $this->reasoningEffort],
            'max_output_tokens' => 1500,
        ];
        if ($previousResponseId !== null) {
            $payload['previous_response_id'] = $previousResponseId;
        }

        $this->logger?->info(sprintf('ai request start: model=%s effort=%s %s', $this->model, $this->reasoningEffort,
            $previousResponseId !== null ? 'continues ' . $previousResponseId : 'new conversation'));
        $started = microtime(true);
        try {
            $response = $this->http->postJson(
                rtrim($this->baseUrl, '/') . '/responses',
                $payload,
                ['Authorization' => 'Bearer ' . $this->apiKey],
                $this->timeout,
            );
        } catch (Throwable $e) {
            $this->logger?->error(sprintf('ai request failed after %dms: %s', $this->elapsedMs($started), Redact::text($e->getMessage(), [$this->apiKey])));
            throw $e;
        }
        $elapsed = $this->elapsedMs($started);
        $body = json_decode($response->body, true);
        if ($response->status !== 200 || !is_array($body)) {
            $message = is_array($body) ? (string) ($body['error']['message'] ?? 'unknown error') : 'invalid response';
            $this->logger?->error(sprintf('ai response HTTP %d after %dms, model=%s: %s', $response->status, $elapsed, $this->model,
                Redact::text($response->body, [$this->apiKey], 500)));
            throw new AiError(sprintf('OpenAI HTTP %d: %s', $response->status, $message));
        }
        $this->logger?->info(sprintf('ai response HTTP 200 after %dms, model=%s', $elapsed, $this->model));
        return AiResponse::fromApi($body);
    }

    private function elapsedMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
