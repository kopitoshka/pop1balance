<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Domain\Model\Player;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\MoscowTime;
use P1Bot\Domain\TournamentService;
use P1Bot\Repository\ConversationRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Clock;
use P1Bot\Support\Logger;
use P1Bot\Support\RuPlural;
use P1Bot\Telegram\TelegramApi;
use P1Bot\View\Html;
use Throwable;

/**
	Runs every minute: reminders, automatic status changes, cleanup.
*/
class CronRunner {

	/**
		@param list<int> $reminder_minutes
	*/
	public function __construct(
		protected TournamentService $tournaments,
		protected ConversationRepository $conversations,
		protected PlayerRepository $players,
		protected Announcer $announcer,
		protected TelegramApi $api,
		protected Clock $clock,
		protected Logger $logger,
		protected array $reminder_minutes,
		protected int $finish_after_hours,
	) {
	}

	/**
		@return array{reminders: int, status_changes: int, conversations_deleted: int}
	*/
	public function run(): array {
		$reminders = 0;
		foreach ($this->tournaments->dueReminders($this->reminder_minutes) as [$t, $minutes]) {
			try {
				$this->remind($t, $minutes);
				$reminders++;
			} catch (Throwable $e) {
				$this->logger->exception($e, 'reminder ' . $t->id);
			} finally {
				// Mark even on failure so a broken chat does not get retried every minute.
				$this->tournaments->markReminded($t->id);
			}
		}

		$changed = $this->tournaments->autoAdvance($this->finish_after_hours);
		$chats = [];
		foreach ($changed as $t) {
			$this->announcer->refresh($t->id);
			$chats[$t->chat_id] = true;
		}
		foreach (array_keys($chats) as $chat_id) {
			// Finished tournaments drop out of the numbering: others may get new numbers.
			$this->announcer->renumber((int) $chat_id);
		}

		return [
			'reminders' => $reminders,
			'status_changes' => count($changed),
			'conversations_deleted' => $this->conversations->deleteExpired($this->clock->now()),
		];
	}

	protected function remind(Tournament $t, int $minutes): void {
		$lineup = $this->tournaments->lineup($t->id);
		$mentions = [];
		foreach ($lineup->main_teams as $entry) {
			foreach ($entry->members as $member) {
				$mentions[] = $this->mention($member->player_id);
			}
		}
		foreach ($lineup->main_solo as $solo) {
			$mentions[] = $this->mention($solo->player_id);
		}

		$text = sprintf(
			'⏰ Через %d %s старт турнира %s (%s)!',
			$minutes,
			RuPlural::choose($minutes, ['минуту', 'минуты', 'минут']),
			Html::b($t->title),
			MoscowTime::format($t->starts_at, 'H:i'),
		);
		$text .= "\n" . Html::e(mb_strtoupper(mb_substr($lineup->summary(), 0, 1)) . mb_substr($lineup->summary(), 1)) . '.';
		if ($mentions !== []) {
			$text .= "\n\nИграют: " . implode(', ', $mentions) . "\nУдачи всем, заходите в игру заранее!";
		}
		if ($lineup->reserveCount() > 0) {
			$text .= "\nРезерв, будьте на связи: если кто-то не придёт, позовём вас.";
		}
		$this->api->sendMessage($t->chat_id, $text, $t->announce_msg_id);
	}

	protected function mention(int $player_id): string {
		$player = $this->players->find($player_id);
		if (!$player instanceof Player) {
			return '#' . $player_id;
		}
		if ($player->username === null && $player->tg_user_id !== null) {
			return '<a href="tg://user?id=' . $player->tg_user_id . '">' . Html::e($player->display_name) . '</a>';
		}
		return Html::e($player->mention());
	}
}
