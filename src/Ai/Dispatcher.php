<?php

declare(strict_types=1);

namespace P1Bot\Ai;

/**
 * Executes a model function call by delegating to domain services.
 * Permissions are checked by the services, never by the model. Stub for now.
 */
final class Dispatcher
{
    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> result passed back to the model
     */
    public function dispatch(string $toolName, array $arguments, int $chatId, int $actorPlayerId): array
    {
        throw new \LogicException('Not implemented yet');
    }
}
