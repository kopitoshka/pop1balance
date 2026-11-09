<?php

declare(strict_types=1);

namespace P1Bot\Support;

use DateTimeZone;
use P1Bot\Telegram\TgUser;

/**
	Readable per-user dialog logs: storage/logs/dialogs/<username>.log (id<tg id>.log without a username).
	One entry per line, continuation lines indented with a tab:

		[2026-11-09 18:42:07 MSK] личка @vasya: хочу соло
		[2026-11-09 18:42:09 MSK] личка бот → @vasya: Записала тебя соло!

	The router opens an interaction (begin) for every user message or button the bot processes;
	DialogLoggingApi then writes the bot's replies to it. Broadcasts (announcements, reminders) happen
	outside an interaction and are not logged here. A null directory disables the log.
*/
class DialogLog {
	public const PRIVATE_SOURCE = 'личка';

	/**
		@var array{file: string, source: string, user: string, chat_id: int, private: bool, message_id: ?int, callback_id: ?string}|null
	*/
	protected ?array $current = null;

	public function __construct(protected ?string $dir, protected Clock $clock) {
	}

	public function enabled(): bool {
		return $this->dir !== null;
	}

	/**
		Starts an interaction: the user's message (or button) the bot is about to process.
	*/
	public function begin(
		TgUser $user,
		int $chat_id,
		string $chat_type,
		string $chat_title,
		?int $message_id = null,
		?string $callback_id = null,
	): void {
		$private = $chat_type === 'private';
		$this->current = [
			'file' => self::fileName($user),
			'source' => $private ? self::PRIVATE_SOURCE : 'группа «' . ($chat_title !== '' ? $chat_title : (string) $chat_id) . '»',
			'user' => self::userLabel($user),
			'chat_id' => $chat_id,
			'private' => $private,
			'message_id' => $message_id,
			'callback_id' => $callback_id,
		];
	}

	public function end(): void {
		$this->current = null;
	}

	/**
		What the user wrote (plain text).
	*/
	public function user(string $text): void {
		if ($this->current !== null) {
			$this->write($this->current['source'] . ' ' . $this->current['user'] . ': ' . $text);
		}
	}

	/**
		A bot reply (HTML is stripped).
	*/
	public function bot(string $html): void {
		if ($this->current !== null) {
			$this->write($this->current['source'] . ' бот → ' . $this->current['user'] . ': ' . self::plain($html));
		}
	}

	/**
		sendMessage: in private every message to this chat; in a group only replies to the user's message.
	*/
	public function onSendMessage(int $chat_id, string $text, ?int $reply_to): void {
		$c = $this->current;
		if ($c !== null && $chat_id === $c['chat_id'] && ($c['private'] || ($reply_to !== null && $reply_to === $c['message_id']))) {
			$this->bot($text);
		}
	}

	/**
		editMessageText: only edits of messages in this user's private chat (group announcements are broadcasts).
	*/
	public function onEditMessage(int $chat_id, string $text): void {
		$c = $this->current;
		if ($c !== null && $c['private'] && $chat_id === $c['chat_id']) {
			$this->bot('(исправила сообщение) ' . $text);
		}
	}

	/**
		answerCallbackQuery with a popup / toast text.
	*/
	public function onCallbackAnswer(string $callback_id, string $text): void {
		$c = $this->current;
		if ($c !== null && $text !== '' && $callback_id === $c['callback_id']) {
			$this->bot('(всплывающее) ' . $text);
		}
	}

	/**
		File name: lowercase username without @, only [a-z0-9_]; id<tg id> when there is none.
	*/
	public static function fileName(TgUser $user): string {
		$name = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $user->username)) ?? '';
		return ($name !== '' ? $name : 'id' . $user->id) . '.log';
	}

	public static function userLabel(TgUser $user): string {
		if ($user->username !== null && $user->username !== '') {
			return '@' . $user->username;
		}
		$name = $user->displayName();
		return ($name !== '' ? $name . ' ' : '') . '(id' . $user->id . ')';
	}

	/**
		HTML to plain text, line breaks kept.
	*/
	public static function plain(string $html): string {
		return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	protected function write(string $entry): void {
		if ($this->dir === null || $this->current === null) {
			return;
		}
		$now = $this->clock->now()->setTimezone(new DateTimeZone('Europe/Moscow'));
		$text = str_replace(["\r\n", "\r"], "\n", rtrim($entry));
		$line = '[' . $now->format('Y-m-d H:i:s') . ' MSK] ' . str_replace("\n", "\n\t", $text);
		if (!is_dir($this->dir)) {
			@mkdir($this->dir, 0775, true);
		}
		$file = $this->dir . '/' . $this->current['file'];
		if (@file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
			error_log('[p1bot] dialog log dir not writable (' . $this->dir . '): ' . $line);
		}
	}
}
