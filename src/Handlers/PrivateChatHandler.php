<?php

declare(strict_types=1);

namespace P1Bot\Handlers;

use P1Bot\App\CreateAccess;
use P1Bot\App\DraftCards;
use P1Bot\App\PrivateGroups;
use P1Bot\App\PrivateViews;
use P1Bot\Domain\AccessPolicy;
use P1Bot\Domain\Exception\DomainError;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\TournamentService;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Clock;
use P1Bot\Telegram\BotIdentity;
use P1Bot\Telegram\ChatAdminSync;
use P1Bot\Telegram\Message;
use P1Bot\Telegram\TelegramApi;
use P1Bot\View\DeepLink;
use P1Bot\View\Texts;

/**
	Private chat with the bot: everything happens here. Deep links from the group (/start t_<id>,
	/start g_<chat id>), all commands, buttons and the AI with all tools. Tournament numbers are
	global (the tournament id). Tournaments are global; create rights come from CreateAccess.
*/
class PrivateChatHandler {
	public const START = 'start';
	public const START_TOURNAMENT = 'start tournament';
	public const START_GROUP = 'start group';
	public const COMMAND = 'command';
	public const UNKNOWN_COMMAND = 'unknown command';
	public const CARD_REPLY = 'card reply';
	public const HELP = 'help';
	public const LEVELS = 'levels';
	public const TOURNAMENTS = 'tournaments';
	public const AI = 'ai';
	public const FALLBACK = 'fallback';

	/**
		Plain words understood without the AI (only when the AI is off; with AI the model answers).
	*/
	protected const KEYWORDS = [
		'помощь' => self::HELP,
		'справка' => self::HELP,
		'помоги' => self::HELP,
		'уровни' => self::LEVELS,
		'турниры' => self::TOURNAMENTS,
		'список' => self::TOURNAMENTS,
		'список турниров' => self::TOURNAMENTS,
	];

	/**
		Commands that refer to tournaments by the group's numbers: they need the current group.
	*/
	
	public function __construct(
		protected TelegramApi $api,
		protected BotIdentity $bot,
		protected AccessPolicy $access,
		protected ChatAdminSync $admin_sync,
		protected PlayerRepository $players,
		protected TournamentService $tournaments,
		protected PrivateGroups $groups,
		protected PrivateViews $views,
		protected DraftCards $drafts,
		protected AdminCommands $commands,
		protected MessageHandler $messages,
		protected Clock $clock,
		protected ?CreateAccess $create_access = null,
	) {
	}

	/**
		What to do with the message; also used for the update log.

		@return array{kind: string, command: ?string, args: string, tournament: ?int, group: ?int}
	*/
	public function decide(Message $message, Player $actor): array {
		$decision = ['kind' => self::AI, 'command' => null, 'args' => '', 'tournament' => null, 'group' => null];
		$command = $message->command();
		if ($command !== null && ($command['bot'] === null || mb_strtolower($command['bot']) === mb_strtolower($this->bot->username))) {
			$name = mb_strtolower($command['name']);
			$decision['command'] = $name;
			$decision['args'] = $command['args'];
			if ($name === 'start') {
				$payload = DeepLink::parse(trim($command['args']));
				if ($payload['tournament'] !== null) {
					return ['kind' => self::START_TOURNAMENT, 'tournament' => $payload['tournament']] + $decision;
				}
				if ($payload['group'] !== null) {
					return ['kind' => self::START_GROUP, 'group' => $payload['group']] + $decision;
				}
				return ['kind' => self::START] + $decision;
			}
			if (!$this->commands->supports($name)) {
				return ['kind' => self::UNKNOWN_COMMAND] + $decision;
			}
			return ['kind' => self::COMMAND] + $decision;
		}
		if ($this->isCardReply($message, $actor)) {
			return ['kind' => self::CARD_REPLY] + $decision;
		}
		if (!$this->messages->hasAi()) {
			$words = mb_strtolower(trim((string) preg_replace('/[\s.,!?…]+/u', ' ', $message->text)));
			return ['kind' => self::KEYWORDS[$words] ?? self::FALLBACK] + $decision;
		}
		// Tournament numbers are global: free-text AI does not need a chosen group.
		// Tournaments are global: free-text AI does not need a chosen group to create.
		return $decision;
	}

	/**
		@param array{kind: string, command: ?string, args: string, tournament: ?int, group: ?int} $decision
	*/
	public function handle(Message $message, Player $actor, array $decision): void {
		$this->players->markDmStarted($actor->id, $this->clock->now());
		$chat_id = $message->chat_id;
		$is_owner = $this->access->isOwner($actor->id);
		$first_name = $message->from !== null ? $message->from->first_name : '';
		switch ($decision['kind']) {
			case self::START_TOURNAMENT:
				$this->startTournament($message, $actor, (int) $decision['tournament']);
				return;
			case self::START_GROUP:
				$this->groups->choose($actor->id, (int) $decision['group']);
				$this->views->list($chat_id, $actor, $is_owner, Texts::welcome($first_name));
				return;
			case self::START:
				$this->views->list($chat_id, $actor, $is_owner, Texts::welcome($first_name));
				return;
			case self::UNKNOWN_COMMAND:
				$this->api->sendMessage($chat_id, 'Такой команды я не знаю 🤔 Подсказка: /help', $message->message_id);
				return;
			case self::HELP:
				$ctx = $this->context($message, $actor);
				$this->api->sendMessage(
					$chat_id,
					Texts::help($this->bot->username, $this->canCreate($actor), $is_owner),
				);
				return;
			case self::LEVELS:
				$this->api->sendMessage($chat_id, Texts::levels());
				return;
			case self::TOURNAMENTS:
				$this->views->list($chat_id, $actor, $is_owner);
				return;
			case self::COMMAND:
				$this->commands->handle(
					$this->context($message, $actor),
					(string) $decision['command'],
					$decision['args'],
				);
				return;
		}
		$this->messages->handle($this->context($message, $actor));
	}

	/**
		Author and rights. is_admin means «may create tournaments» (CreateAccess);
		manage rights are still author|owner per tournament.
	*/
	public function context(Message $message, Player $actor): Context {
		$is_owner = $this->access->isOwner($actor->id);
		$group_id = $this->groups->current($actor->id);
		if ($group_id !== null) {
			$this->admin_sync->syncIfStale($group_id);
		}
		return new Context(
			$message,
			$actor,
			$this->canCreate($actor),
			$is_owner,
			$group_id,
			$group_id !== null ? $this->groups->title($group_id) : '',
		);
	}

	protected function canCreate(Player $actor): bool {
		if ($this->create_access !== null) {
			return $this->create_access->canCreate($actor->id);
		}
		return $this->groups->canCreate($actor->id);
	}

	/**
		@param array{kind: string, command: ?string, args: string, tournament: ?int, group: ?int} $decision
	*/
	public static function describe(array $decision): string {
		switch ($decision['kind']) {
			case self::START_TOURNAMENT:
				return 'start t_' . (int) $decision['tournament'];
			case self::START_GROUP:
				return 'start g_' . (int) $decision['group'];
			case self::COMMAND:
			case self::UNKNOWN_COMMAND:
				return $decision['kind'] . ' /' . $decision['command'];
			case self::FALLBACK:
				return 'fallback text (AI off: OPENAI_API_KEY is empty)';
		}
		return $decision['kind'];
	}

	protected function startTournament(Message $message, Player $actor, int $tournament_id): void {
		$chat_id = $message->chat_id;
		$first_name = $message->from !== null ? $message->from->first_name : '';
		$is_owner = $this->access->isOwner($actor->id);
		try {
			$t = $this->tournaments->get($tournament_id);
		} catch (DomainError $e) {
			$t = null;
		}
		$visible = $t !== null && (
			in_array($t->status, TournamentStatus::active(), true)
			|| ($t->status === TournamentStatus::DRAFT && $this->access->canManageTournament($t, $actor->id))
		);
		if ($t === null || !$visible) {
			$this->views->list(
				$chat_id,
				$actor,
				$is_owner,
				Texts::welcome($first_name) . "\n\nЭтот турнир уже не активен. Вот что есть сейчас:",
			);
			return;
		}
		if ($t->chat_id !== null) {
			$this->groups->choose($actor->id, $t->chat_id);
		}
		if ($t->status === TournamentStatus::DRAFT) {
			$this->drafts->show($t->id, $chat_id, null, $actor->id);
			return;
		}
		$this->api->sendMessage($chat_id, Texts::welcomeTournament($first_name));
		$this->views->tournament($chat_id, $t, $actor);
	}

	protected function isCardReply(Message $message, Player $actor): bool {
		return $message->reply_to_message_id !== null
			&& $message->isReplyToUser($this->bot->bot_id)
			&& $this->tournaments->findByPrivateCard($message->reply_to_message_id, $actor->id) !== null;
	}
}
