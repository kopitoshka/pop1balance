<?php

declare(strict_types=1);

namespace P1Bot\Ai;

/** Model provider (OpenAI Responses API). */
interface AiClient
{
    /**
     * @param list<array<string, mixed>> $input  Responses API input items
     * @param list<array<string, mixed>> $tools  function tools from Tools::definitions()
     * @param string|null $previousResponseId     continue a response (tool outputs round)
     */
    public function createResponse(string $instructions, array $input, array $tools, ?string $previousResponseId = null): AiResponse;
}
