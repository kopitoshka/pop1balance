<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Domain\Model\Player;
use P1Bot\Domain\TeamService;
use P1Bot\Domain\TournamentService;
use P1Bot\Repository\PendingActionRepository;
use P1Bot\Support\Clock;
use P1Bot\Telegram\CallbackQuery;
use P1Bot\Telegram\TelegramApi;

/**
	Text-input pending actions (rename, new team name/captain, team-add search):
	abort on foreign callbacks and /commands; «✖️ Отмена» clears them.
*/
class PendingTextGuard {
	public const CANCEL_DATA = 'pcancel';

	/** @var list<string> */
	public const TEXT_OPS = ['org_team_name', 'org_team_captain', 'org_rename', 'team_add', 'reg_fill'];

	public function __construct(
		protected PendingActionRepository $pending,
		protected Clock $clock,
		protected TelegramApi $api,
		protected TournamentService $tournaments,
		protected TeamService $teams,
		protected ?OrganizerTeams $organizer = null,
		protected ?TeamCards $cards = null,
	) {
	}

	public function setOrganizer(?OrganizerTeams $organizer): void {
		$this->organizer = $organizer;
	}

	public function setCards(?TeamCards $cards): void {
		$this->cards = $cards;
	}

	/**
		Inline row with cancel for text-waiting prompts.
		@return list<array{text: string, callback_data: string}>
	*/
	public static function cancelRow(): array {
		return [['text' => '✖️ Отмена', 'callback_data' => self::CANCEL_DATA]];
	}

	/**
		@param list<list<array<string, string>>> $rows
		@return list<list<array<string, string>>>
	*/
	public static function withCancel(array $rows = []): array {
		$rows[] = self::cancelRow();
		return $rows;
	}

	public function clearChat(int $actor_id, int $chat_id): int {
		return $this->pending->clearChat($actor_id, $chat_id);
	}

	/**
		Before handling a private callback: drop pendings unless this button belongs to them.
	*/
	public function abortIfForeignCallback(int $actor_id, int $chat_id, string $data): void {
		if ($data === self::CANCEL_DATA || str_starts_with($data, 'sug:') || str_starts_with($data, 'rfill:')) {
			return;
		}
		$row = $this->pending->peekLatest($actor_id, $chat_id, $this->clock->now());
		if ($row === null) {
			return;
		}
		$action = $row['action'];
		$op = $action['op'] ?? null;
		if (is_string($op) && in_array($op, self::TEXT_OPS, true) && $this->callbackBelongsTo($op, $action, $data)) {
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
		$row = $this->latestTextPending($actor->id, $chat_id);
		$cleared = $this->clearChat($actor->id, $chat_id);
		if ($cleared === 0 && $row === null) {
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
		$this->restoreContext($chat_id, $actor, $row['action'] ?? null);
	}

	/**
		@param array<string, mixed> $action
	*/
	protected function callbackBelongsTo(string $op, array $action, string $data): bool {
		return match ($op) {
			'team_add' => (bool) (
				preg_match('/^tadd:(\d+):(\d+)$/', $data, $m)
				&& (int) $m[1] === (int) ($action['team_id'] ?? 0)
			),
			'reg_fill' => (bool) preg_match('/^rfill:(\d+):(\d+)$/', $data),
			'org_team_captain' => (bool) (
				preg_match('/^otcc:(\d+):(\d+)$/', $data, $m)
				&& (int) $m[1] === (int) ($action['tournament_id'] ?? 0)
			),
			'org_team_name', 'org_rename' => false,
			default => false,
		};
	}

	/**
		@return array{id: int, actor_player_id: int, chat_id: int, action: array<string, mixed>}|null
	*/
	protected function latestTextPending(int $actor_id, int $chat_id): ?array {
		foreach (self::TEXT_OPS as $op) {
			$row = $this->pending->peekLatest($actor_id, $chat_id, $this->clock->now(), $op);
			if ($row !== null) {
				return $row;
			}
		}
		return null;
	}

	/**
		@param array<string, mixed>|null $action
	*/
	protected function restoreContext(int $chat_id, Player $actor, ?array $action): void {
		if ($action === null) {
			return;
		}
		$op = $action['op'] ?? null;
		try {
			if (($op === 'org_rename' || $op === 'team_add') && $this->cards !== null) {
				$team_id = (int) ($action['team_id'] ?? 0);
				if ($team_id > 0) {
					$entry = $this->teams->get($team_id);
					$t = $this->tournaments->get($entry->team->tournament_id);
					$this->cards->send($chat_id, $t, $entry, $actor);
				}
				return;
			}
			if (($op === 'org_team_name' || $op === 'org_team_captain') && $this->organizer !== null) {
				$tid = (int) ($action['tournament_id'] ?? 0);
				if ($tid > 0) {
					$t = $this->tournaments->get($tid);
					$this->organizer->sendList($chat_id, $t, $actor);
				}
			}
		} catch (\Throwable) {
			// Best-effort restore; cancel already confirmed.
		}
	}
}
