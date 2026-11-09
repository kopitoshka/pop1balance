<?php

declare(strict_types=1);

namespace P1Bot\Ai;

use P1Bot\Http\HttpClient;

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

        $response = $this->http->postJson(
            rtrim($this->baseUrl, '/') . '/responses',
            $payload,
            ['Authorization' => 'Bearer ' . $this->apiKey],
            $this->timeout,
        );
        $body = json_decode($response->body, true);
        if ($response->status !== 200 || !is_array($body)) {
            $message = is_array($body) ? (string) ($body['error']['message'] ?? 'unknown error') : 'invalid response';
            throw new AiError(sprintf('OpenAI HTTP %d: %s', $response->status, $message));
        }
        return AiResponse::fromApi($body);
    }
}
