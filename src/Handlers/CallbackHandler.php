<?php

declare(strict_types=1);

namespace P1Bot\Handlers;

use P1Bot\Domain\Model\Player;
use P1Bot\Telegram\CallbackQuery;
use P1Bot\Telegram\TelegramApi;

/** Inline button presses. */
final class CallbackHandler
{
    public function __construct(private TelegramApi $api)
    {
    }

    public function handle(CallbackQuery $query, Player $player): void
    {
        $this->api->answerCallbackQuery($query->id);
    }
}
