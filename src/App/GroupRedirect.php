<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Domain\Exception\DomainError;
use P1Bot\Domain\TournamentService;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Telegram\BotIdentity;
use P1Bot\Telegram\Message;
use P1Bot\View\DeepLink;

/**
	Deep-link payload for group → private redirects: t_<id> when the tournament is clear, else g_<chat>.
*/
class GroupRedirect {

	public function __construct(
		protected BotIdentity $bot,
		protected TournamentService $tournaments,
		protected TournamentResolver $resolver,
	) {
	}

	/**
		Deep link payload: t_<id>, g_<chat id>.
	*/
	public function payload(Message $message): string {
		$id = $this->tournamentId($message);
		return $id !== null ? 't_' . $id : 'g_' . $message->chat_id;
	}

	public function url(Message $message): string {
		return DeepLink::url($this->bot->username, $this->payload($message));
	}

	/**
		Public for GroupChat (reply-to announcement).
	*/
	public function tournamentIdPublic(Message $message): ?int {
		return $this->tournamentId($message);
	}

	protected function tournamentId(Message $message): ?int {
		if ($message->reply_to_message_id !== null && $message->isReplyToUser($this->bot->bot_id)) {
			$t = $this->tournaments->findByAnnounceMessage($message->chat_id, $message->reply_to_message_id)
				?? $this->tournaments->findByCardMessage($message->chat_id, $message->reply_to_message_id);
			if ($t !== null) {
				return $t->id;
			}
		}
		$number = self::numberFrom($message);
		if ($number === null) {
			return null;
		}
		try {
			return $this->resolver->resolve(null, $number, TournamentStatus::active())->id;
		} catch (DomainError $e) {
			return null;
		}
	}

	/**
		A short tournament number from the message: "/cmd 2 …", "№2", "#2", "турнир 2".
	*/
	public static function numberFrom(Message $message): ?int {
		$command = $message->command();
		if ($command !== null && preg_match('/^[#№]?(\d{1,9})(?:\s|$)/u', trim($command['args']), $m)) {
			return (int) $m[1];
		}
		if (preg_match('/(?:[#№]\s?|турнир\w*\s+)(\d{1,9})(?!\d|[.:])/u', mb_strtolower($message->text), $m)) {
			return (int) $m[1];
		}
		return null;
	}
}
