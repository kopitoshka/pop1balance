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
	The group only announces: anything else addressed to the bot gets «Давай в личке 👉» with a deep
	link. The link opens the right tournament when it is clear from the message: a reply to its
	announcement or card, or its number (/edit 2, «№2», «турнир 2»). Otherwise it names the group.
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

	protected function tournamentId(Message $message): ?int {
		if ($message->reply_to_message_id !== null && $message->isReplyToUser($this->bot->bot_id)) {
			$t = $this->tournaments->findByAnnounceMessage($message->chat_id, $message->reply_to_message_id)
				?? $this->tournaments->findByCardMessage($message->chat_id, $message->reply_to_message_id);
			if ($t !== null) {
				return $t->id;
			}
		}
		$number = self::number($message);
		if ($number === null) {
			return null;
		}
		try {
			return $this->resolver->resolve($message->chat_id, $number, TournamentStatus::active())->id;
		} catch (DomainError $e) {
			return null;
		}
	}

	/**
		A short tournament number from the message: "/cmd 2 …", "№2", "#2", "турнир 2".
	*/
	protected static function number(Message $message): ?int {
		$command = $message->command();
		if ($command !== null && preg_match('/^[#№]?(\d{1,3})(?:\s|$)/u', trim($command['args']), $m)) {
			return (int) $m[1];
		}
		if (preg_match('/(?:[#№]\s?|турнир\w*\s+)(\d{1,3})(?!\d|[.:])/u', mb_strtolower($message->text), $m)) {
			return (int) $m[1];
		}
		return null;
	}
}
