<?php

declare(strict_types=1);

namespace P1Bot\Support;

/** Strips secrets (bot tokens, API keys) from text before it goes to a log. */
final class Redact
{
    /** @param list<string> $secrets exact values to hide as well */
    public static function text(string $text, array $secrets = [], int $maxLength = 500): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $text = str_replace($secret, '***', $text);
            }
        }
        $text = preg_replace('/\bsk-[A-Za-z0-9_\-]{8,}/', 'sk-***', $text) ?? $text;
        $text = preg_replace('/\b\d{5,}:[A-Za-z0-9_\-]{20,}/', '***:***', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        if (mb_strlen($text) > $maxLength) {
            $text = mb_substr($text, 0, $maxLength) . '…';
        }
        return $text;
    }
}
