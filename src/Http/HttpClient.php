<?php

declare(strict_types=1);

namespace P1Bot\Http;

/** Minimal HTTP client so Telegram/OpenAI calls can be mocked in tests. */
interface HttpClient
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $json    request body, sent as application/json
     */
    public function postJson(string $url, array $json, array $headers = [], int $timeout = 15): HttpResponse;
}
