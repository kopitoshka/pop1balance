<?php

declare(strict_types=1);

namespace P1Bot\Http;

final class HttpResponse
{
    public function __construct(
        public int $status,
        public string $body,
    ) {
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        $data = json_decode($this->body, true);
        if (!is_array($data)) {
            throw new HttpError(sprintf('Invalid JSON response (HTTP %d)', $this->status));
        }
        return $data;
    }
}
