<?php

declare(strict_types=1);

namespace P1Bot\Ai;

/**
	Model provider (OpenAI Responses API).
*/
interface AiClient {

	/**
		@param list<array<string, mixed>> $input  Responses API input items
		@param list<array<string, mixed>> $tools  function tools from Tools::definitions()
		@param string|null $previous_response_id     continue a response (tool outputs round)
	*/
	public function createResponse(
		string $instructions,
		array $input,
		array $tools,
		?string $previous_response_id = null,
	): AiResponse;
}
