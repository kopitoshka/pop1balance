<?php

declare(strict_types=1);

namespace P1Bot\Ai;

/** OpenAI Responses API client (model gpt-6-luna). Implementation comes in the next step. */
interface AiClient
{
    /**
     * @param list<array<string, mixed>> $input  conversation items (system + history + user message)
     * @param list<array<string, mixed>> $tools  function schemas from Tools::definitions()
     * @return AiReply
     */
    public function respond(array $input, array $tools): AiReply;
}
