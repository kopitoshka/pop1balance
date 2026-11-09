<?php

declare(strict_types=1);

namespace P1Bot\Handlers;

use P1Bot\Domain\AccessPolicy;
use P1Bot\Domain\Model\Player;
use P1Bot\Repository\ChatRepository;
use P1Bot\Repository\EditedReplyRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Clock;
use P1Bot\Support\Logger;
use P1Bot\Telegram\BotIdentity;
use P1Bot\Telegram\ChatAdminSync;
use P1Bot\Telegram\Message;
use P1Bot\Telegram\TelegramApi;
use P1Bot\Telegram\TgUser;
use P1Bot\Telegram\Update;
use P1Bot\View\Html;
use P1Bot\View\Messages;
use P1Bot\View\Texts;

/**
	Entry point for every update. In groups the bot reacts only to slash commands,
	@mentions of the bot and replies to its messages. Private chats are ignored for now.
*/
class UpdateRouter {

	public function __construct(
		protected TelegramApi $api,
		protected BotIdentity $bot,
		protected ChatRepository $chats,
		protected PlayerRepository $players,
		protected ChatAdminSync $admin_sync,
		protected AccessPolicy $access,
		protected Clock $clock,
		protected AdminCommands $commands,
		protected MessageHandler $messages,
		protected CallbackHandler $callbacks,
		protected ?EditedReplyRepository $edited_replies = null,
		protected ?Logger $logger = null,
	) {
	}

	public function handle(Update $update): void {
		$membership = $update->botMembershipChange();
		if ($membership !== null) {
			$this->log($update, null, sprintf('membership: chat %d (%s) %s', $membership['chat_id'], $membership['type'],
				$membership['is_member'] ? ($membership['was_member'] ? 'still member' : 'bot added') : 'bot removed'));
			$this->onMembership($membership);
			return;
		}
		if ($update->callback_query !== null) {
			$query = $update->callback_query;
			if ($query->chat_id === null || $query->from->is_bot) {
				$this->log($update, null, 'ignored: callback without chat or from a bot');
				return;
			}
			$this->log($update, null, 'handled: callback ' . $query->data, [
				'chat' => $query->chat_id,
				'from' => $query->from->id,
				'username' => $query->from->username,
			]);
			$this->chats->ensure($query->chat_id, $query->chat_title);
			$player = $this->upsertPlayer($query->from);
			$this->callbacks->handle($query, $player);
			return;
		}
		if ($update->message !== null) {
			$this->onMessage($update, $update->message);
			return;
		}
		if ($update->edited_message !== null) {
			$this->onEditedMessage($update, $update->edited_message);
			return;
		}
		$this->log($update, null, 'ignored: unsupported update type');
	}

	/**
		Edits are never processed (no commands, no AI). If the edit mentions the bot or replies to it,
		say once per message that a new message is needed.
	*/
	protected function onEditedMessage(Update $update, Message $message): void {
		if (!$message->isGroup()) {
			$this->log($update, $message, 'ignored: edited, not a group (' . $message->chat_type . ')');
			return;
		}
		if ($message->from === null || $message->from->is_bot) {
			$this->log($update, $message, 'ignored: edited, from a bot or no sender');
			return;
		}
		if (!$message->isAddressedTo($this->bot->username, $this->bot->bot_id)) {
			$this->log($update, $message, 'ignored: edited, ' . $this->whyNotAddressed($message));
			return;
		}
		if ($this->edited_replies === null) {
			$this->log($update, $message, 'ignored: edited, dedupe storage unavailable');
			return;
		}
		if (!$this->edited_replies->claim($message->chat_id, $message->message_id, $this->clock->now())) {
			$this->log($update, $message, 'ignored: edited, already replied to this message');
			return;
		}
		$this->log($update, $message, 'edited: replied once');
		$this->api->sendMessage($message->chat_id, Html::e(Messages::EDITED_MESSAGE), $message->message_id);
	}

	protected function onMessage(Update $update, Message $message): void {
		if (!$message->isGroup()) {
			$this->log(
				$update,
				$message,
				$message->chat_type === 'private' ? 'ignored: private chat' : 'ignored: not a group (' . $message->chat_type . ')',
			);
			return;
		}
		if ($message->from === null || $message->from->is_bot) {
			$this->log($update, $message, 'ignored: from a bot or no sender');
			return;
		}

		$command = $message->command();
		$is_our_command = $command !== null
			&& ($command['bot'] === null || mb_strtolower($command['bot']) === mb_strtolower($this->bot->username))
			&& $this->commands->supports($command['name']);
		if (!$is_our_command && !$message->isAddressedTo($this->bot->username, $this->bot->bot_id)) {
			if ($command !== null && $command['bot'] !== null && mb_strtolower($command['bot']) !== mb_strtolower($this->bot->username)) {
				$reason = sprintf('ignored: command /%s@%s is for another bot', $command['name'], $command['bot']);
			} elseif ($command !== null) {
				$reason = sprintf('ignored: unknown command /%s', $command['name']);
			} else {
				$reason = 'ignored: ' . $this->whyNotAddressed($message);
			}
			$this->log($update, $message, $reason);
			return;
		}

		$this->log($update, $message, $is_our_command && $command !== null
			? 'handled: command /' . $command['name']
			: 'handled: ' . $this->messages->mode());

		$this->chats->ensure($message->chat_id, $message->chat_title);
		$actor = $this->upsertPlayer($message->from);
		$this->registerMentionedPlayers($message);
		$this->admin_sync->syncIfStale($message->chat_id);
		$context = new Context(
			$message,
			$actor,
			$this->access->isChatAdmin($message->chat_id, $actor->id),
			$this->access->isOwner($actor->id),
		);

		if ($is_our_command && $command !== null) {
			$this->commands->handle($context, $command['name'], $command['args']);
			return;
		}
		$this->messages->handle($context);
	}

	/**
		Why a group message is not for us; names the username we compared against.
	*/
	protected function whyNotAddressed(Message $message): string {
		$mentions = $message->mentionedUsernames();
		$ours = '@' . mb_strtolower($this->bot->username);
		if ($this->bot->username === '') {
			return 'no mention/reply (TELEGRAM_BOT_USERNAME is empty!)';
		}
		$bot_like = array_values(array_filter($mentions, static function (string $m): bool {
			return (bool) preg_match('/bot$/u', $m);
		}));
		if ($bot_like !== []) {
			return sprintf(
				'wrong bot username in mention (%s, expected %s from TELEGRAM_BOT_USERNAME)',
				implode(', ', $bot_like),
				$ours,
			);
		}
		if ($message->reply_to_from !== null && $message->reply_to_from->is_bot && $message->reply_to_from->id !== $this->bot->bot_id) {
			return sprintf('reply to another bot (id %d, ours is %d)', $message->reply_to_from->id, $this->bot->bot_id);
		}
		return sprintf('no mention/reply (expected %s)', $ours);
	}

	/**
		One info line per update: id, type, chat, sender, text start and the decision.

		@param array<string, mixed> $extra
	*/
	protected function log(Update $update, ?Message $message, string $decision, array $extra = []): void {
		if ($this->logger === null) {
			return;
		}
		$context = ['type' => $update->type];
		if ($message !== null) {
			$text = preg_replace('/\s+/u', ' ', $message->text) ?? $message->text;
			$context += [
				'chat' => $message->chat_id,
				'chat_type' => $message->chat_type,
				'from' => $message->from !== null ? $message->from->id : null,
				'username' => $message->from !== null && $message->from->username !== null ? '@' . $message->from->username : null,
				'text' => mb_strlen($text) > 100 ? mb_substr($text, 0, 100) . '…' : $text,
				'bot' => '@' . $this->bot->username,
			];
		}
		$this->logger->info(
			sprintf('update %d %s: %s', $update->update_id, $update->type, $decision),
			$context + $extra,
		);
	}

	/**
		@param array{chat_id: int, title: string, type: string, is_member: bool, was_member: bool} $m
	*/
	protected function onMembership(array $m): void {
		if ($m['type'] !== 'group' && $m['type'] !== 'supergroup') {
			return;
		}
		if (!$m['is_member']) {
			if ($this->chats->exists($m['chat_id'])) {
				$this->chats->deactivate($m['chat_id']);
			}
			return;
		}
		$this->chats->ensure($m['chat_id'], $m['title']);
		if (!$m['was_member']) {
			$this->api->sendMessage($m['chat_id'], Texts::greeting($this->bot->username));
		}
	}

	protected function upsertPlayer(TgUser $user): Player {
		return $this->players->ensureByTelegram($user->id, $user->username, $user->displayName(), $this->clock->now());
	}

	/**
		text_mention users have a real id: create/attach them so later lookups work.
	*/
	protected function registerMentionedPlayers(Message $message): void {
		foreach ($message->mentionedUsers($this->bot->username) as $mention) {
			if (isset($mention['user']) && !$mention['user']->is_bot) {
				$this->upsertPlayer($mention['user']);
			}
		}
	}
}
