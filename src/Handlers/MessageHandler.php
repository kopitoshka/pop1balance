<?php

declare(strict_types=1);

namespace P1Bot\Handlers;

use P1Bot\Ai\Assistant;
use P1Bot\App\Confirmation;
use P1Bot\App\DraftCards;
use P1Bot\App\TournamentListing;
use P1Bot\Domain\Exception\DomainError;
use P1Bot\Domain\TournamentService;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Support\Logger;
use P1Bot\Telegram\BotIdentity;
use P1Bot\Telegram\TelegramApi;
use P1Bot\View\Html;
use P1Bot\View\Texts;
use Throwable;

/**
	Free-text messages addressed to the bot: AI when configured, otherwise a short help.
*/
class MessageHandler {

	public function __construct(
		protected TelegramApi $api,
		protected BotIdentity $bot,
		protected ?Assistant $assistant,
		protected Logger $logger,
		protected ?TournamentService $tournaments = null,
		protected ?DraftCards $drafts = null,
		protected ?TournamentListing $listing = null,
	) {
	}

	public function handle(Context $ctx): void {
		$chat_id = $ctx->chatId();
		$reply_to = $ctx->message->message_id;

		if ($this->handleCardReply($ctx)) {
			return;
		}

		if ($this->assistant === null) {
			$this->api->sendMessage($chat_id, Texts::fallback($this->bot->username), $reply_to);
			return;
		}

		try {
			$reply = $this->assistant->reply($ctx);
		} catch (Throwable $e) {
			$this->logger->exception($e, 'assistant');
			$this->api->sendMessage($chat_id, Texts::somethingWentWrong(), $reply_to);
			return;
		}

		if ($reply['card'] !== null && $this->drafts !== null) {
			try {
				// The card itself ends the turn: «Публикуем или что-то поправить?» with buttons.
				$this->drafts->show($reply['card'], $reply_to, $ctx->actor->id, $reply['history']);
				return;
			} catch (DomainError $e) {
				// The draft was published or cancelled meanwhile: fall back to the model's text.
			}
		}
		$message_id = null;
		if (!empty($reply['list']) && $this->listing !== null) {
			// The pretty list goes as is; the model only adds a short phrase.
			foreach ($this->listing->messages($chat_id, $ctx->is_admin) as $list) {
				$message_id = $this->api->sendMessage($chat_id, $list, $reply_to);
			}
		}
		if (!empty($reply['levels'])) {
			$message_id = $this->api->sendMessage($chat_id, Texts::levels(), $reply_to);
		}
		if ($reply['text'] !== '' || $message_id === null) {
			$message_id = $this->api->sendMessage(
				$chat_id,
				Html::e($reply['text'] !== '' ? $reply['text'] : 'Готово!'),
				$reply_to,
			);
		}
		($reply['remember'])($message_id);
	}

	/**
		A clear "да / публикуем" in reply to the current draft card publishes it right away,
		without the AI. Anything else goes on to the AI (or a hint when AI is off).
	*/
	/**
		For the update log: what will answer a mention.
	*/
	public function mode(): string {
		return $this->assistant !== null ? 'ai' : 'fallback text (AI off: OPENAI_API_KEY is empty)';
	}

	protected function handleCardReply(Context $ctx): bool {
		$reply_to = $ctx->message->reply_to_message_id;
		if ($reply_to === null || $this->tournaments === null || $this->drafts === null || !$ctx->message->isReplyToUser($this->bot->bot_id)) {
			return false;
		}
		$t = $this->tournaments->findByCardMessage($ctx->chatId(), $reply_to);
		if ($t === null || $t->status !== TournamentStatus::DRAFT || !$this->drafts->canConfirm($t, $ctx->actor->id)) {
			return false;
		}
		if (Confirmation::isClearYes($ctx->message->textWithoutMention($this->bot->username))) {
			try {
				$this->drafts->publish($ctx->actor->id, $t->id, $reply_to);
				$this->api->sendMessage(
					$ctx->chatId(),
					'Опубликовал! Анонс уже в чате, регистрация открыта 🎉',
					$ctx->message->message_id,
				);
			} catch (DomainError $e) {
				$this->api->sendMessage($ctx->chatId(), Html::e($e->getMessage()), $ctx->message->message_id);
			}
			return true;
		}
		if ($this->assistant === null) {
			$this->api->sendMessage(
				$ctx->chatId(),
				'Не публикую, пока ты явно не подтвердишь. Поправить можно командой, например: /edit ' . (int) $this->drafts->numberOf($t) . ' время 23.11 21:00. Если всё верно — ответь «да» или нажми «Публикуем».',
				$ctx->message->message_id,
			);
			return true;
		}
		return false;
	}
}
