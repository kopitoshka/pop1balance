<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Domain\Exception\DomainError;
use P1Bot\Domain\Exception\NotFound;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\TeamService;
use P1Bot\Domain\TournamentService;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Handlers\AdminCommands;
use P1Bot\Handlers\Context;
use P1Bot\Support\Logger;
use P1Bot\Support\RandomSource;
use P1Bot\Telegram\BotIdentity;
use P1Bot\Telegram\Message;
use P1Bot\Telegram\TelegramApi;
use P1Bot\View\DeepLink;
use P1Bot\View\Html;
use P1Bot\View\Messages;
use P1Bot\View\Texts;

/**
	What to do with a group message addressed to the bot: info answers, solo join/leave,
	management redirects (random phrase), or a short «не поняла» + /help hint.
	No free-form AI chat in groups.
*/
class GroupChat {
	public const INTENT_INFO = 'info';
	public const INTENT_JOIN = 'join';
	public const INTENT_LEAVE = 'leave';
	public const INTENT_MANAGE = 'manage';
	public const INTENT_FALLBACK = 'fallback';

	public function __construct(
		protected TelegramApi $api,
		protected BotIdentity $bot,
		protected TournamentService $tournaments,
		protected TeamService $teams,
		protected TournamentResolver $resolver,
		protected GroupRedirect $redirect,
		protected AdminCommands $commands,
		protected RandomSource $random,
		protected Logger $logger,
		protected ?Announcer $announcer = null,
	) {
	}

	/**
		Classify free text (bot mention already stripped for matching; commands handled separately).
	*/
	public function intent(string $text): string {
		$t = mb_strtolower(trim($text));
		$t = (string) preg_replace('/\s+/u', ' ', $t);
		if ($t === '' || $this->isInfo($t)) {
			return self::INTENT_INFO;
		}
		if ($this->isJoin($t)) {
			return self::INTENT_JOIN;
		}
		if ($this->isLeave($t)) {
			return self::INTENT_LEAVE;
		}
		if ($this->isManage($t)) {
			return self::INTENT_MANAGE;
		}
		return self::INTENT_FALLBACK;
	}

	/**
		Handle a group message that is addressed to the bot (mention, reply, or our command).
	*/
	public function handle(Context $ctx, ?array $command): void {
		$message = $ctx->message;
		if ($command !== null) {
			$name = mb_strtolower($command['name']);
			if (in_array($name, ['help', 'start', 'tournaments', 'history', 'info', 'levels'], true)) {
				$this->commands->handle($ctx, $name, $command['args']);
				return;
			}
			// /new /edit /open /close /cancel /level /balance → manage redirect
			$this->redirectManage($message, $command['name']);
			return;
		}

		$text = $message->textWithoutMention($this->bot->username);
		switch ($this->intent($text)) {
			case self::INTENT_INFO:
				$this->answerInfo($ctx, $text);
				return;
			case self::INTENT_JOIN:
				$this->join($ctx, $text);
				return;
			case self::INTENT_LEAVE:
				$this->leave($ctx, $text);
				return;
			case self::INTENT_MANAGE:
				$this->redirectManage($message);
				return;
			default:
				$this->fallback($ctx);
		}
	}

	protected function answerInfo(Context $ctx, string $text): void {
		$t = mb_strtolower($text);
		if ($t === '' || preg_match('/\b(help|помощь|справка|хелп|что умеешь|как пользоваться)\b/u', $t)) {
			$this->commands->handle($ctx, 'help', '');
			return;
		}
		if (preg_match('/\b(уровень|уровни|levels)\b/u', $t)) {
			$this->commands->handle($ctx, 'levels', '');
			return;
		}
		if ($this->isTournamentsAsk($t)) {
			$this->commands->handle($ctx, 'tournaments', '');
			return;
		}
		if ($this->isHistoryAsk($t)) {
			$this->commands->handle($ctx, 'history', '');
			return;
		}
		$number = GroupRedirect::numberFrom($ctx->message);
		if ($number !== null) {
			$this->commands->handle($ctx, 'info', (string) $number);
			return;
		}
		// Bare «какие турниры?» already matched; fallback — list or help
		if (preg_match('/турнир/u', $t)) {
			$this->commands->handle($ctx, 'tournaments', '');
			return;
		}
		$this->commands->handle($ctx, 'help', '');
	}

	protected function join(Context $ctx, string $text): void {
		$player = $ctx->actor;
		$target = $this->resolveOpenTournament($ctx->message, $text);
		if ($target === 'ambiguous') {
			$this->sendRedirect($ctx->message, Messages::ambiguousRedirect($this->random));
			return;
		}
		if ($target === null) {
			$this->api->sendMessage(
				$ctx->chatId(),
				Html::e('Сейчас нет турниров с открытой регистрацией 🙂'),
				$ctx->message->message_id,
			);
			return;
		}
		if (!$this->tournaments->isUserRegistrationOpen($target)) {
			$this->api->sendMessage($ctx->chatId(), Html::e(Messages::REGISTRATION_CLOSED), $ctx->message->message_id);
			return;
		}
		$lineup = $this->tournaments->lineup($target->id);
		if ($lineup->placementOf($player->id) !== null) {
			$this->api->sendMessage($ctx->chatId(), Html::e(Messages::ALREADY_SOLO), $ctx->message->message_id);
			return;
		}
		try {
			$this->teams->joinSolo($target->id, $player->id);
		} catch (ValidationError $e) {
			$this->api->sendMessage($ctx->chatId(), Html::e($e->getMessage()), $ctx->message->message_id);
			return;
		} catch (DomainError $e) {
			$this->api->sendMessage($ctx->chatId(), Html::e($e->getMessage()), $ctx->message->message_id);
			return;
		}
		if ($this->announcer !== null) {
			$this->announcer->refresh($target->id);
		}
		$lineup = $this->tournaments->lineup($target->id);
		$place = $lineup->placementOf($player->id);
		if ($place === 'reserve') {
			$reply = Messages::joinedReserve($target->title, (int) $lineup->reservePosition($player->id));
		} else {
			$reply = Messages::joinedMain($target->title, $lineup->occupied(), $target->max_players);
		}
		$this->api->sendMessage($ctx->chatId(), Html::e($reply), $ctx->message->message_id);
	}

	protected function leave(Context $ctx, string $text): void {
		$player = $ctx->actor;
		$target = $this->resolveLeaveTournament($ctx->message, $text, $player);
		if ($target === 'ambiguous') {
			$this->sendRedirect($ctx->message, Messages::ambiguousRedirect($this->random));
			return;
		}
		if ($target === null) {
			$this->api->sendMessage($ctx->chatId(), Html::e(Messages::NOT_REGISTERED), $ctx->message->message_id);
			return;
		}
		$team = $this->teams->teamOf($target->id, $player->id);
		if ($team !== null) {
			if ($team->team->captain_id === $player->id) {
				$this->sendRedirect($ctx->message, Messages::CAPTAIN_LEAVE, 't_' . $target->id);
				return;
			}
			try {
				$this->teams->leaveTeam($player->id, $team->team->id);
			} catch (DomainError $e) {
				$this->api->sendMessage($ctx->chatId(), Html::e($this->friendlyLeaveError($e)), $ctx->message->message_id);
				return;
			}
			if ($this->announcer !== null) {
				$this->announcer->refresh($target->id);
			}
			$this->api->sendMessage(
				$ctx->chatId(),
				Html::e(Messages::leftTeam($team->team->name, $target->title)),
				$ctx->message->message_id,
			);
			return;
		}
		$solo = $this->tournaments->lineup($target->id)->placementOf($player->id);
		if ($solo === null) {
			$this->api->sendMessage($ctx->chatId(), Html::e(Messages::NOT_REGISTERED), $ctx->message->message_id);
			return;
		}
		try {
			$this->teams->leaveSolo($target->id, $player->id);
		} catch (DomainError $e) {
			$this->api->sendMessage($ctx->chatId(), Html::e($this->friendlyLeaveError($e)), $ctx->message->message_id);
			return;
		}
		if ($this->announcer !== null) {
			$this->announcer->refresh($target->id);
		}
		$this->api->sendMessage(
			$ctx->chatId(),
			Html::e(Messages::leftTournament($target->title)),
			$ctx->message->message_id,
		);
	}

	protected function redirectManage(Message $message, string $command_name = ''): void {
		$payload = $this->redirect->payload($message);
		$this->logger->info(sprintf(
			'group: redirect (%s) start=%s',
			$command_name !== '' ? '/' . $command_name : 'manage',
			$payload,
		));
		$this->sendRedirect($message, Messages::manageRedirect($this->random), $payload);
	}

	/**
		Unrecognized group mention: short fixed hint, no AI chatter and no tournament dump.
	*/
	protected function fallback(Context $ctx): void {
		$this->api->sendMessage($ctx->chatId(), Messages::GROUP_FALLBACK, $ctx->message->message_id);
	}

	/**
		@param string|null $payload deep-link payload; null = from GroupRedirect
	*/
	protected function sendRedirect(Message $message, string $text, ?string $payload = null): void {
		$payload = $payload ?? $this->redirect->payload($message);
		$this->api->sendMessage(
			$message->chat_id,
			Html::e($text),
			$message->message_id,
			DeepLink::button(Messages::GROUP_REDIRECT_BUTTON, DeepLink::url($this->bot->username, $payload)),
		);
	}

	/**
		@return Tournament|'ambiguous'|null
	*/
	protected function resolveOpenTournament(Message $message, string $text): Tournament|string|null {
		$open = $this->openForUsers();
		$number = GroupRedirect::numberFrom($message);
		if ($number !== null) {
			try {
				$t = $this->resolver->resolve(null, $number, TournamentStatus::active());
			} catch (DomainError $e) {
				return null;
			}
			return $this->tournaments->isUserRegistrationOpen($t) ? $t : $t; // still return; join checks lock
		}
		if ($message->reply_to_message_id !== null && $message->isReplyToUser($this->bot->bot_id)) {
			$id = $this->redirect->tournamentIdPublic($message);
			if ($id !== null) {
				try {
					return $this->tournaments->get($id);
				} catch (NotFound $e) {
					return null;
				}
			}
		}
		if (count($open) === 1) {
			return $open[0];
		}
		if (count($open) === 0) {
			return null;
		}
		return 'ambiguous';
	}

	/**
		Leave: among tournaments the player is in (active), or by number / reply.
		@return Tournament|'ambiguous'|null
	*/
	protected function resolveLeaveTournament(Message $message, string $text, Player $player): Tournament|string|null {
		$number = GroupRedirect::numberFrom($message);
		if ($number !== null) {
			try {
				return $this->resolver->resolve(null, $number, TournamentStatus::active());
			} catch (DomainError $e) {
				return null;
			}
		}
		if ($message->reply_to_message_id !== null && $message->isReplyToUser($this->bot->bot_id)) {
			$id = $this->redirect->tournamentIdPublic($message);
			if ($id !== null) {
				try {
					return $this->tournaments->get($id);
				} catch (NotFound $e) {
					return null;
				}
			}
		}
		$mine = [];
		foreach ($this->tournaments->listAllActive() as $t) {
			if ($this->tournaments->lineup($t->id)->placementOf($player->id) !== null) {
				$mine[] = $t;
			}
		}
		if (count($mine) === 1) {
			return $mine[0];
		}
		if (count($mine) === 0) {
			return null;
		}
		return 'ambiguous';
	}

	/**
		@return list<Tournament>
	*/
	protected function openForUsers(): array {
		$result = [];
		foreach ($this->tournaments->listAllActive() as $t) {
			if ($t->status === TournamentStatus::REGISTRATION && $this->tournaments->isUserRegistrationOpen($t)) {
				$result[] = $t;
			}
		}
		return $result;
	}

	protected function friendlyLeaveError(DomainError $e): string {
		$msg = $e->getMessage();
		if (str_contains($msg, 'не меня') || str_contains($msg, 'закрыл')) {
			return Messages::REGISTRATION_CLOSED;
		}
		return $msg;
	}

	protected function isInfo(string $t): bool {
		if (preg_match('/\b(help|помощь|справка|хелп|что умеешь|как пользоваться)\b/u', $t)) {
			return true;
		}
		if (preg_match('/\b(уровень|уровни|levels)\b/u', $t) && !preg_match('/создай|поставь|поменяй/u', $t)) {
			return true;
		}
		if ($this->isTournamentsAsk($t)) {
			return true;
		}
		if ($this->isHistoryAsk($t)) {
			return true;
		}
		return false;
	}

	protected function isHistoryAsk(string $t): bool {
		return (bool) preg_match(
			'/\b(история|прошлые турниры|прошедшие турниры|что было|бывшие турниры|history)\b/u',
			$t,
		);
	}

	protected function isTournamentsAsk(string $t): bool {
		if (preg_match('/\b(что нового|новости|что есть|есть ли что|что по играм|что по расписанию)\b/u', $t)) {
			return true;
		}
		if (preg_match('/\b(какие турниры|список турниров|турниры скоро|какие есть турниры|что за турнир)\b/u', $t)) {
			return true;
		}
		if (preg_match('/\b(турнир\w*|tournaments?)\b/u', $t)
			&& preg_match('/\b(какие|список|скоро|ближайш|активн|покажи|есть)\b/u', $t)
		) {
			return true;
		}
		if (preg_match('/^(какие|что|покажи|расскажи).{0,40}турнир/u', $t)) {
			return true;
		}
		return false;
	}

	protected function isJoin(string $t): bool {
		return (bool) preg_match(
			'/\b(запиши\s+меня|запиши\s+соло|записать\s+меня|хочу\s+(участвовать|играть|на\s+турнир|соло)|'
			. 'запиши\s+на\s+турнир|добавь\s+меня|можно\s+соло|участвовать\s+соло|играю\s+соло)\b/u',
			$t,
		);
	}

	protected function isLeave(string $t): bool {
		return (bool) preg_match(
			'/\b(удали\s+меня|выпиши\s+меня|отпиши\s+меня|сними\s+меня|убери\s+меня|не\s+хочу\s+(участвовать|играть)|отменить\s+запись|отпиш[иу]\s+меня)\b/u',
			$t,
		);
	}

	protected function isManage(string $t): bool {
		if (preg_match('/\b(создай|создать|новый турнир|открой регистрац|закрой регистрац|отмени турнир|опубликуй|поправь турнир)\b/u', $t)) {
			return true;
		}
		if (preg_match('/\b(запиши\s+команд|собери\s+команд|создай\s+команд|распусти\s+команд|добавь\s+@|убери\s+@|замени\s+@)\b/u', $t)) {
			return true;
		}
		if (preg_match('/\b(edit|new|open|close|cancel)\b/u', $t)) {
			return true;
		}
		return false;
	}
}
