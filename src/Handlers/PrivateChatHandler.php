<?php

declare(strict_types=1);

namespace P1Bot\Handlers;

use P1Bot\Ai\PrivateAssistant;
use P1Bot\App\TournamentListing;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\MoscowTime;
use P1Bot\Repository\ChatRepository;
use P1Bot\Support\Clock;
use P1Bot\Support\Logger;
use P1Bot\Telegram\BotIdentity;
use P1Bot\Telegram\Message;
use P1Bot\Telegram\TelegramApi;
use P1Bot\View\Html;
use P1Bot\View\Messages;
use P1Bot\View\Texts;
use Throwable;

/**
	Private chat with the bot: any text is answered, no mention needed. Read-only:
	help, levels, the tournament list and free questions (AI without write tools).
	Everything that changes data is redirected to the group. The owner may also use
	his owner-only commands (/level, /balance) here.
*/
class PrivateChatHandler {
	public const HELP = 'help';
	public const LEVELS = 'levels';
	public const TOURNAMENTS = 'tournaments';
	public const OWNER_COMMAND = 'owner_command';
	public const REDIRECT = 'redirect';
	public const AI = 'ai';
	public const FALLBACK = 'fallback';

	/**
		Plain words and commands understood without the AI.
	*/
	protected const KEYWORDS = [
		'help' => self::HELP,
		'start' => self::HELP,
		'помощь' => self::HELP,
		'справка' => self::HELP,
		'помоги' => self::HELP,
		'levels' => self::LEVELS,
		'уровни' => self::LEVELS,
		'tournaments' => self::TOURNAMENTS,
		'турниры' => self::TOURNAMENTS,
		'список' => self::TOURNAMENTS,
		'список турниров' => self::TOURNAMENTS,
		'info' => self::TOURNAMENTS,
	];

	/**
		Owner-only commands that also work in private (see AccessPolicy::LEVELS_OWNER_ONLY).
	*/
	protected const OWNER_COMMANDS = ['level', 'balance'];

	public function __construct(
		protected TelegramApi $api,
		protected BotIdentity $bot,
		protected ChatRepository $chats,
		protected TournamentListing $listing,
		protected AdminCommands $commands,
		protected Clock $clock,
		protected Logger $logger,
		protected ?PrivateAssistant $assistant = null,
	) {
	}

	/**
		What to do with the message; also used for the update log.

		@return array{kind: string, command: ?string}
	*/
	public function decide(Message $message, bool $is_owner): array {
		$command = $message->command();
		if ($command !== null) {
			$name = mb_strtolower($command['name']);
			if (isset(self::KEYWORDS[$name])) {
				return ['kind' => self::KEYWORDS[$name], 'command' => $name];
			}
			if ($is_owner && in_array($name, self::OWNER_COMMANDS, true)) {
				return ['kind' => self::OWNER_COMMAND, 'command' => $name];
			}
			return ['kind' => self::REDIRECT, 'command' => $name];
		}
		$words = mb_strtolower(trim((string) preg_replace('/[\s.,!?…]+/u', ' ', $message->text)));
		if (isset(self::KEYWORDS[$words])) {
			return ['kind' => self::KEYWORDS[$words], 'command' => null];
		}
		return ['kind' => $this->assistant !== null ? self::AI : self::FALLBACK, 'command' => null];
	}

	/**
		@param array{kind: string, command: ?string} $decision
	*/
	public function handle(Message $message, Player $actor, bool $is_owner, array $decision): void {
		$chat_id = $message->chat_id;
		switch ($decision['kind']) {
			case self::HELP:
				$this->api->sendMessage($chat_id, Texts::privateHelp($this->bot->username, $is_owner));
				return;
			case self::LEVELS:
				$this->api->sendMessage($chat_id, Texts::levels());
				return;
			case self::TOURNAMENTS:
				$this->sendList($chat_id, $actor, $is_owner);
				return;
			case self::OWNER_COMMAND:
				$command = $message->command();
				$this->commands->handle(
					new Context($message, $actor, true, true),
					(string) $decision['command'],
					$command['args'] ?? '',
				);
				return;
			case self::REDIRECT:
				$this->api->sendMessage($chat_id, Html::e(Messages::PRIVATE_REDIRECT));
				return;
			case self::FALLBACK:
				$this->api->sendMessage($chat_id, Texts::privateFallback());
				return;
		}
		$this->answerWithAi($message, $actor, $is_owner);
	}

	protected function sendList(int $chat_id, Player $actor, bool $is_owner): void {
		$messages = $this->listing->privateMessages($this->chats->listActiveGroups(), $actor->id, $is_owner);
		if ($messages === []) {
			$this->api->sendMessage(
				$chat_id,
				'Сейчас активных турниров нет. Как только появятся — покажу здесь: /tournaments',
			);
			return;
		}
		foreach ($messages as $text) {
			$this->api->sendMessage($chat_id, $text);
		}
	}

	protected function answerWithAi(Message $message, Player $actor, bool $is_owner): void {
		$chat_id = $message->chat_id;
		if ($this->assistant === null) {
			$this->api->sendMessage($chat_id, Texts::privateFallback());
			return;
		}
		try {
			$reply = $this->assistant->reply($message->text, $this->context($actor, $is_owner));
		} catch (Throwable $e) {
			$this->logger->exception($e, 'private assistant');
			$this->api->sendMessage($chat_id, Texts::somethingWentWrong());
			return;
		}
		if ($reply['list']) {
			$this->sendList($chat_id, $actor, $is_owner);
		}
		if ($reply['levels']) {
			$this->api->sendMessage($chat_id, Texts::levels());
		}
		if ($reply['text'] !== '') {
			$this->api->sendMessage($chat_id, Html::e($reply['text']));
		} elseif (!$reply['list'] && !$reply['levels']) {
			$this->api->sendMessage($chat_id, Texts::privateFallback());
		}
	}

	/**
		Facts for the model: who writes and what is going on in the groups (plain text).
	*/
	protected function context(Player $actor, bool $is_owner): string {
		$groups = $this->chats->listActiveGroups();
		$list = $this->listing->privateMessages($groups, $actor->id, $is_owner, 20000);
		$plain = trim(html_entity_decode(strip_tags(implode("\n\n", $list)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		return implode("\n", [
			'Контекст (для тебя, не пересказывай его целиком):',
			'Сейчас: ' . MoscowTime::format($this->clock->now()),
			sprintf(
				'Пишет: %s%s%s.',
				$actor->display_name !== '' ? $actor->display_name : 'игрок',
				$actor->username !== null ? ' (@' . $actor->username . ')' : '',
				$is_owner ? ', главный админ' : '',
			),
			'Групп с ботом: ' . count($groups) . '.',
			'Турниры:',
			$plain !== '' ? $plain : 'активных турниров нет.',
		]);
	}
}
