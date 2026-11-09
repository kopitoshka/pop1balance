<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Domain\AccessPolicy;
use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\TournamentService;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Repository\BalanceRepository;
use P1Bot\Repository\ConversationRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Clock;
use P1Bot\Support\Logger;
use P1Bot\Telegram\TelegramApi;
use P1Bot\View\TournamentCard;
use Throwable;

/**
	Draft confirmation flow: show the card with «Публикуем или что-то поправить?» and publish
	only after an explicit confirmation of the version that was shown.
*/
class DraftCards {

	public function __construct(
		protected TelegramApi $api,
		protected TournamentService $tournaments,
		protected PlayerRepository $players,
		protected BalanceRepository $balances,
		protected ConversationRepository $conversations,
		protected Announcer $announcer,
		protected AccessPolicy $access,
		protected Clock $clock,
		protected Logger $logger,
		protected int $conversation_ttl_minutes = 30,
	) {
	}

	/**
		Global tournament number users type (/edit N): the tournament id.
	*/
	public function numberOf(Tournament $t): ?int {
		return $this->announcer->number($t)->number;
	}

	public function cardText(Tournament $t): string {
		$this->announcer->number($t);
		return TournamentCard::render(
			$t,
			$this->players->find($t->organizer_id),
			$t->balance_id !== null ? $this->balances->find($t->balance_id) : null,
		);
	}

	/**
		Sends the card to $chat_id (the private chat with the organizer) and remembers it as the
		version to confirm. The published announcement goes to the tournament's group.

		@param list<array<string, mixed>> $history dialog to continue when the organizer replies to the card
		@return int message id of the card
	*/
	public function show(int $tournament_id, int $chat_id, ?int $reply_to, int $actor_id, array $history = []): int {
		$t = $this->tournaments->get($tournament_id);
		if ($t->status !== TournamentStatus::DRAFT) {
			throw new ValidationError('Этот турнир уже не черновик.');
		}
		$text = $this->cardText($t);
		$message_id = $this->api->sendMessage($chat_id, $text, $reply_to, TournamentCard::buttons($t->id));
		$this->tournaments->setCardMessage($t->id, $message_id, $actor_id);

		$history[] = ['role' => 'assistant', 'content' => self::plain($text)];
		$this->conversations->save(
			null,
			$chat_id,
			$actor_id,
			$message_id,
			array_slice($history, -12),
			$this->clock->now()->modify("+{$this->conversation_ttl_minutes} minutes"),
		);
		return $message_id;
	}

	/**
		Publishes a confirmed draft: opens registration and posts the announcement.

		@param int|null $card_message_id the card the confirmation refers to (button / reply), if known
	*/
	public function publish(int $actor_id, int $tournament_id, ?int $card_message_id = null): Tournament {
		$t = $this->tournaments->get($tournament_id);
		if ($t->status !== TournamentStatus::DRAFT) {
			throw new ValidationError(sprintf(
				'Турнир «%s» уже не черновик (%s).',
				$t->title,
				TournamentStatus::label($t->status),
			));
		}
		if ($t->card_msg_id === null) {
			throw new ValidationError('Сначала нужно показать актуальную карточку турнира и получить подтверждение.');
		}
		if ($card_message_id !== null && $card_message_id !== $t->card_msg_id) {
			throw new ValidationError('Эта карточка устарела: турнир с тех пор меняли. Подтверди последнюю карточку.');
		}
		if (!$this->canConfirm($t, $actor_id)) {
			throw new PermissionDenied('Опубликовать турнир может только его автор или главный админ.');
		}

		$card = $this->cardText($t);
		$published = $this->tournaments->openRegistration($actor_id, $t->id);
		$this->announcer->publish($published->id);
		$this->announcer->renumber($t->chat_id, $t->id);
		try {
			$this->api->editMessageText(
				$this->cardChatId($t),
				(int) $t->card_msg_id,
				TournamentCard::published($card),
				['reply_markup' => ['inline_keyboard' => []]],
			);
		} catch (Throwable $e) {
			$this->logger->exception($e, 'card published ' . $t->id);
		}
		return $this->tournaments->get($t->id);
	}

	/**
		Who may confirm a draft card: the author (organizer) or the bot owner.
	*/
	public function canConfirm(Tournament $t, int $actor_id): bool {
		return $t->organizer_id === $actor_id || $this->access->isOwner($actor_id);
	}

	/**
		The card lives in the private chat of the person it was shown to (chat id = Telegram user id).
	*/
	protected function cardChatId(Tournament $t): int {
		$shown_to = $t->card_shown_to !== null ? $this->players->find($t->card_shown_to) : null;
		return $shown_to !== null && $shown_to->tg_user_id !== null ? $shown_to->tg_user_id : $t->chat_id;
	}

	protected static function plain(string $html): string {
		return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}
}
