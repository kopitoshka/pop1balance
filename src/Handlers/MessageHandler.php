<?php

declare(strict_types=1);

namespace P1Bot\Handlers;

use P1Bot\Ai\Assistant;
use P1Bot\App\Confirmation;
use P1Bot\App\DraftCards;
use P1Bot\Domain\Exception\DomainError;
use P1Bot\Domain\TournamentService;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Support\Logger;
use P1Bot\Telegram\BotIdentity;
use P1Bot\Telegram\TelegramApi;
use P1Bot\View\Html;
use P1Bot\View\Texts;
use Throwable;

/** Free-text messages addressed to the bot: AI when configured, otherwise a short help. */
final class MessageHandler
{
    public function __construct(
        private TelegramApi $api,
        private BotIdentity $bot,
        private ?Assistant $assistant,
        private Logger $logger,
        private ?TournamentService $tournaments = null,
        private ?DraftCards $drafts = null,
    ) {
    }

    public function handle(Context $ctx): void
    {
        $chatId = $ctx->chatId();
        $replyTo = $ctx->message->messageId;

        if ($this->handleCardReply($ctx)) {
            return;
        }

        if ($this->assistant === null) {
            $this->api->sendMessage($chatId, Texts::fallback($this->bot->username), $replyTo);
            return;
        }

        try {
            $reply = $this->assistant->reply($ctx);
        } catch (Throwable $e) {
            $this->logger->exception($e, 'assistant');
            $this->api->sendMessage($chatId, Texts::somethingWentWrong(), $replyTo);
            return;
        }

        if ($reply['card'] !== null && $this->drafts !== null) {
            try {
                // The card itself ends the turn: «Публикуем или что-то поправить?» with buttons.
                $this->drafts->show($reply['card'], $replyTo, $ctx->actor->id, $reply['history']);
                return;
            } catch (DomainError $e) {
                // The draft was published or cancelled meanwhile: fall back to the model's text.
            }
        }
        $messageId = $this->api->sendMessage($chatId, Html::e($reply['text']), $replyTo);
        ($reply['remember'])($messageId);
    }

    /**
     * A clear "да / публикуем" in reply to the current draft card publishes it right away,
     * without the AI. Anything else goes on to the AI (or a hint when AI is off).
     */
    private function handleCardReply(Context $ctx): bool
    {
        $replyTo = $ctx->message->replyToMessageId;
        if ($replyTo === null || $this->tournaments === null || $this->drafts === null || !$ctx->message->isReplyToUser($this->bot->botId)) {
            return false;
        }
        $t = $this->tournaments->findByCardMessage($ctx->chatId(), $replyTo);
        if ($t === null || $t->status !== TournamentStatus::DRAFT || !$this->drafts->canConfirm($t, $ctx->actor->id)) {
            return false;
        }
        if (Confirmation::isClearYes($ctx->message->textWithoutMention($this->bot->username))) {
            try {
                $this->drafts->publish($ctx->actor->id, $t->id, $replyTo);
                $this->api->sendMessage($ctx->chatId(), 'Опубликовал! Анонс уже в чате, регистрация открыта 🎉', $ctx->message->messageId);
            } catch (DomainError $e) {
                $this->api->sendMessage($ctx->chatId(), Html::e($e->getMessage()), $ctx->message->messageId);
            }
            return true;
        }
        if ($this->assistant === null) {
            $this->api->sendMessage(
                $ctx->chatId(),
                'Не публикую, пока ты явно не подтвердишь. Поправить можно командой, например: /edit ' . $t->id . ' время 23.11 21:00. Если всё верно — ответь «да» или нажми «Публикуем».',
                $ctx->message->messageId,
            );
            return true;
        }
        return false;
    }
}
