<?php

declare(strict_types=1);

namespace P1Bot\Handlers;

use P1Bot\Ai\Assistant;
use P1Bot\App\Confirmation;
use P1Bot\App\DraftCards;
use P1Bot\App\PrivateGroups;
use P1Bot\App\TournamentListing;
use P1Bot\Domain\Exception\DomainError;
use P1Bot\Domain\TournamentService;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Support\Logger;
use P1Bot\Telegram\BotIdentity;
use P1Bot\Telegram\TelegramApi;
use P1Bot\View\Html;
use P1Bot\View\PlayersList;
use P1Bot\View\Texts;
use Throwable;

/**
	Free-text messages addressed to the bot: AI when configured (info only), otherwise a short help.
*/
class MessageHandler {
	public const CARD_HINT = 'Не публикую, пока ты явно не подтвердишь. Поправить — кнопкой «✏️ Поправить» на карточке черновика. Если всё верно — нажми «Публикуем».';

	public function __construct(
		protected TelegramApi $api,
		protected BotIdentity $bot,
		protected ?Assistant $assistant,
		protected Logger $logger,
		protected ?TournamentService $tournaments = null,
		protected ?DraftCards $drafts = null,
		protected ?TournamentListing $listing = null,
		protected ?PrivateGroups $groups = null,
		protected ?PlayersList $players_list = null,
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

		$message_id = null;
		if (!empty($reply['help'])) {
			$message_id = $this->api->sendMessage(
				$chat_id,
				Texts::help($this->bot->username, $ctx->is_admin, $ctx->is_super, $ctx->can_su, $ctx->can_manage_su),
				$reply_to,
			);
		}
		if (!empty($reply['list']) && $this->listing !== null) {
			$message_id = $this->sendList($ctx);
		}
		if (!empty($reply['past_list']) && $this->listing !== null) {
			$message_id = $this->sendHistory($ctx);
		}
		if (!empty($reply['levels'])) {
			$message_id = $this->api->sendMessage($chat_id, Texts::levels(), $reply_to);
		}
		if (!empty($reply['players']) && $this->players_list !== null) {
			$message_id = $this->sendPlayers($ctx);
		}
		$suggest = $reply['suggest_markup'] ?? [];
		$extra = $suggest !== [] ? $suggest : [];
		if ($reply['text'] !== '' || $message_id === null || $extra !== []) {
			$fallback = $suggest !== [] ? 'Может, ты про кого-то из этих?' : 'Готово!';
			$message_id = $this->api->sendMessage(
				$chat_id,
				Html::e($reply['text'] !== '' ? $reply['text'] : $fallback),
				$reply_to,
				$extra,
			);
		}
		($reply['remember'])($message_id);
	}

	public function hasAi(): bool {
		return $this->assistant !== null;
	}

	/**
		For the update log: what will answer a message.
	*/
	public function mode(): string {
		return $this->assistant !== null ? 'ai' : 'fallback text (AI off: OPENAI_API_KEY is empty)';
	}

	/**
		/history list for the AI path.
		@return int|null id of the last message sent
	*/
	protected function sendHistory(Context $ctx): ?int {
		$message_id = null;
		$messages = $this->listing->historyMessages();
		if ($messages === []) {
			return $this->api->sendMessage($ctx->chatId(), 'Пока прошедших турниров нет 🙂', $ctx->message->message_id);
		}
		foreach ($messages as $list) {
			$message_id = $this->api->sendMessage($ctx->chatId(), $list, $ctx->message->message_id);
		}
		return $message_id;
	}

	/**
		The tournament list: in private all groups with «open» buttons, in a group its own list.

		@return int|null id of the last message sent
	*/
	protected function sendList(Context $ctx): ?int {
		$message_id = null;
		if ($ctx->isPrivate() && $this->groups !== null) {
			$groups = $this->groups->all();
			$messages = $this->listing->privateMessages($groups, $ctx->actor->id, $ctx->is_super);
			$buttons = TournamentListing::openButtons(
				$this->listing->privateTournaments($groups, $ctx->actor->id, $ctx->is_super),
				$groups,
			);
			foreach ($messages as $i => $list) {
				$last = $i === count($messages) - 1;
				$message_id = $this->api->sendMessage(
					$ctx->chatId(),
					$list,
					$ctx->message->message_id,
					$last ? $buttons : [],
				);
			}
			return $message_id;
		}
		foreach ($this->listing->messages($ctx->groupId(), false) as $list) {
			$message_id = $this->api->sendMessage($ctx->chatId(), $list, $ctx->message->message_id);
		}
		return $message_id;
	}

	/**
		@return int|null id of the last message sent
	*/
	protected function sendPlayers(Context $ctx): ?int {
		$message_id = null;
		$link = $this->players_list->editLinkCallback($ctx->is_super, $ctx->isPrivate());
		foreach ($this->players_list->messages($ctx->is_super, $ctx->isPrivate(), $link) as $chunk) {
			$message_id = $this->api->sendMessage($ctx->chatId(), $chunk, $ctx->message->message_id);
		}
		return $message_id;
	}

	/**
		A clear "да / публикуем" in reply to the current draft card publishes it right away,
		without the AI. Any other reply to that card gets a short hint (buttons on the card),
		and is not sent to the model.
	*/
	protected function handleCardReply(Context $ctx): bool {
		$reply_to = $ctx->message->reply_to_message_id;
		if ($reply_to === null || $this->tournaments === null || $this->drafts === null || !$ctx->message->isReplyToUser($this->bot->bot_id)) {
			return false;
		}
		$t = $ctx->isPrivate()
			? $this->tournaments->findByPrivateCard($reply_to, $ctx->actor->id)
			: $this->tournaments->findByCardMessage($ctx->chatId(), $reply_to);
		if ($t === null || $t->status !== TournamentStatus::DRAFT || !$this->drafts->canConfirm($t, $ctx->actor->id)) {
			return false;
		}
		if (Confirmation::isClearYes($ctx->message->textWithoutMention($this->bot->username))) {
			try {
				$this->drafts->publish($ctx->actor->id, $t->id, $reply_to);
				$this->api->sendMessage(
					$ctx->chatId(),
					$this->publishedText(),
					$ctx->message->message_id,
				);
			} catch (DomainError $e) {
				$this->api->sendMessage($ctx->chatId(), Html::e($e->getMessage()), $ctx->message->message_id);
			}
			return true;
		}
		$this->api->sendMessage($ctx->chatId(), self::CARD_HINT, $ctx->message->message_id);
		return true;
	}

	public function publishedText(?int $group_id = null): string {
		unset($group_id);
		return 'Опубликовала! Анонс уже во всех группах, регистрация открыта 🎉';
	}
}
