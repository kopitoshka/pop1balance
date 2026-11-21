<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Domain\Model\Player;
use P1Bot\Repository\PendingActionRepository;
use P1Bot\Support\Clock;
use P1Bot\Telegram\CallbackQuery;
use P1Bot\Telegram\TelegramApi;

/**
	Guard for pending player actions in the private chat: a /command or a foreign button
	drops them (the /level «Может, ты про…» picks keep theirs, and so does the team form's
	contacts request). «✖️ Отмена» (pcancel, old messages) clears them as well.
*/
class PendingTextGuard {
	public const CANCEL_DATA = 'pcancel';

	public function __construct(
		protected PendingActionRepository $pending,
		protected Clock $clock,
		protected TelegramApi $api,
	) {
	}

	public function clearChat(int $actor_id, int $chat_id): int {
		return $this->pending->clearChat($actor_id, $chat_id);
	}

	public function isWaiting(int $actor_id, int $chat_id, string $op): bool {
		return $this->pending->peekLatest($actor_id, $chat_id, $this->clock->now(), $op) !== null;
	}

	/**
		@return array{id: int, actor_player_id: int, chat_id: int, action: array<string, mixed>}|null
	*/
	public function takeWaiting(int $actor_id, int $chat_id, string $op): ?array {
		return $this->pending->takeLatest($actor_id, $chat_id, $this->clock->now(), $op);
	}


	/**
		Before handling a private callback: drop pendings unless this button belongs to them.
	*/
	public function abortIfForeignCallback(int $actor_id, int $chat_id, string $data): void {
		if ($data === self::CANCEL_DATA || str_starts_with($data, 'sug:')) {
			return;
		}
		$row = $this->pending->peekLatest($actor_id, $chat_id, $this->clock->now());
		if ($row === null || ($row['action']['op'] ?? null) === TeamForm::CONTACTS_OP) {
			return;
		}
		$this->clearChat($actor_id, $chat_id);
	}

	/**
		Abort when the user sends a slash-command.
	*/
	public function abortOnCommand(int $actor_id, int $chat_id): void {
		$this->clearChat($actor_id, $chat_id);
	}

	/**
		pcancel button.
	*/
	public function handleCancel(CallbackQuery $query, Player $actor): void {
		$chat_id = (int) $query->chat_id;
		if ($this->clearChat($actor->id, $chat_id) === 0) {
			$this->api->answerCallbackQuery($query->id, 'Уже нечего отменять.');
			return;
		}
		$this->api->answerCallbackQuery($query->id, 'Отменила');
		$this->api->sendMessage(
			$chat_id,
			'Отменила.',
			$query->message_id,
			['reply_markup' => ['remove_keyboard' => true]],
		);
	}
}
