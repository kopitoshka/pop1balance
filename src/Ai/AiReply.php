<?php

declare(strict_types=1);

namespace P1Bot\Ai;

/** Model output: free text and/or requested function calls. */
final class AiReply
{
    /**
     * @param list<array{name: string, arguments: array<string, mixed>, call_id: string}> $toolCalls
     */
    public function __construct(
        public string $text,
        public array $toolCalls = [],
    ) {
    }
}
