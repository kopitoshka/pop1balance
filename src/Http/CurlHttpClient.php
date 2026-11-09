<?php

declare(strict_types=1);

namespace P1Bot\Http;

final class CurlHttpClient implements HttpClient
{
    public function postJson(string $url, array $json, array $headers = [], int $timeout = 15): HttpResponse
    {
        $body = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $headerLines = ['Content-Type: application/json', 'Accept: application/json'];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new HttpError('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($response)) {
            throw new HttpError('HTTP request failed: ' . $error);
        }
        return new HttpResponse($status, $response);
    }
}
