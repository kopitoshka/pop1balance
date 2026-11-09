<?php

declare(strict_types=1);

namespace P1Bot\Ai;

/** Parsed model output: text and/or requested function calls. */
final class AiResponse
{
    /**
     * @param list<array{call_id: string, name: string, arguments: array<string, mixed>}> $toolCalls
     */
    public function __construct(
        public string $id,
        public string $text,
        public array $toolCalls = [],
    ) {
    }

    /** @param array<string, mixed> $body Responses API response object */
    public static function fromApi(array $body): self
    {
        $text = '';
        $calls = [];
        foreach ($body['output'] ?? [] as $item) {
            $type = $item['type'] ?? '';
            if ($type === 'message') {
                foreach ($item['content'] ?? [] as $part) {
                    if (($part['type'] ?? '') === 'output_text') {
                        $text .= (string) ($part['text'] ?? '');
                    }
                }
            } elseif ($type === 'function_call') {
                $args = json_decode((string) ($item['arguments'] ?? '{}'), true);
                $calls[] = [
                    'call_id' => (string) ($item['call_id'] ?? ''),
                    'name' => (string) ($item['name'] ?? ''),
                    'arguments' => is_array($args) ? $args : [],
                ];
            }
        }
        return new self((string) ($body['id'] ?? ''), trim($text), $calls);
    }
}
