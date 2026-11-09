<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Domain\Model\Player;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\MoscowTime;
use P1Bot\Domain\TournamentService;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Repository\ChatRepository;
use P1Bot\Repository\ConversationRepository;
use P1Bot\Repository\GroupNoticeRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Clock;
use P1Bot\Support\Logger;
use P1Bot\Support\RuPlural;
use P1Bot\Telegram\TelegramApi;
use P1Bot\Telegram\TelegramError;
use P1Bot\View\DeepLink;
use P1Bot\View\Html;
use Throwable;

/**
	Runs every minute: reminders, automatic status changes, cleanup.

	At the first reminder mark (the largest REMINDER_MINUTES, 60 by default) the tournament's own group
	gets its usual reminder and, with REMIND_ALL_GROUPS, every other group the bot is in gets a short
	announcement with the deep-link button. Each group gets at most one such message per tournament
	(group_notices). Later reminders (15 minutes) go to the tournament's own group only.
*/
class CronRunner {
	public const GROUP_BUTTON = Announcer::BUTTON;

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
		protected ?ChatRepository $chats = null,
		protected ?GroupNoticeRepository $notices = null,
		protected bool $remind_all_groups = false,
		protected string $bot_username = '',
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
		$first_mark = $this->reminder_minutes !== [] && $minutes === max($this->reminder_minutes);
		if ($first_mark && $this->notices !== null) {
			// The own group's reminder is its «in an hour» message: exactly one, even if cron overlaps.
			if (!$this->notices->claim($t->id, $t->chat_id, $this->clock->now())) {
				$this->logger->info(sprintf('reminder %d: group %d already notified, skipped', $t->id, $t->chat_id));
				return;
			}
			$this->sendOwnReminder($t, $minutes);
			$this->notices->setResult($t->id, $t->chat_id, 'sent');
			if ($this->remind_all_groups) {
				$this->notifyOtherGroups($t, $minutes);
			}
			return;
		}
		$this->sendOwnReminder($t, $minutes);
	}

	/**
		The short announcement for every other active group; failures are logged and skipped,
		a group the bot was removed from is marked inactive.
	*/
	protected function notifyOtherGroups(Tournament $t, int $minutes): void {
		if ($this->chats === null || $this->notices === null) {
			return;
		}
		$text = $this->groupNotice($t, $minutes);
		$markup = DeepLink::button(self::GROUP_BUTTON, DeepLink::tournament($this->bot_username, $t->id));
		$sent = 0;
		foreach (array_keys($this->chats->listActiveGroups()) as $chat_id) {
			if ($chat_id === $t->chat_id || !$this->notices->claim($t->id, $chat_id, $this->clock->now())) {
				continue;
			}
			try {
				$this->api->sendMessage($chat_id, $text, null, $markup);
				$this->notices->setResult($t->id, $chat_id, 'sent');
				$sent++;
			} catch (Throwable $e) {
				$this->notices->setResult($t->id, $chat_id, 'error: ' . $e->getMessage());
				if (self::isGone($e)) {
					$this->chats->deactivate($chat_id);
					$this->logger->info(sprintf('group notice %d: bot is not in group %d any more, marked inactive', $t->id, $chat_id));
				} else {
					$this->logger->exception($e, sprintf('group notice %d to %d', $t->id, $chat_id));
				}
			}
		}
		$this->logger->info(sprintf('group notice %d «%s»: sent to %d other group(s)', $t->id, $t->title, $sent));
	}

	/**
		«Tournament in an hour» for groups other than the tournament's own one (HTML).
	*/
	public function groupNotice(Tournament $t, int $minutes): string {
		$lineup = $this->tournaments->lineup($t->id);
		$when = $minutes === 60
			? 'Через час'
			: sprintf('Через %d %s', $minutes, RuPlural::choose($minutes, ['минуту', 'минуты', 'минут']));
		$lines = [
			sprintf('⏰ %s стартует турнир %s!', $when, Html::b($t->title)),
			'🗓 ' . MoscowTime::format($t->starts_at, 'd.m.Y ⏰ H:i'),
			sprintf('👥 Команды по %d %s', $t->team_size, RuPlural::choose($t->team_size, ['игроку', 'игрока', 'игроков'])),
			'⚖️ Уровень: ' . Html::e($t->levelLabel()),
			sprintf('🎟 Занято %d/%d', $lineup->occupied(), $t->max_players),
			'',
			$this->tournaments->isUserRegistrationOpen($t)
				? 'Ещё можно успеть! Записаться соло или командой — у меня в личке 👇'
				: 'Регистрация уже закрыта, но вопросы можно задать мне в личке 👇',
		];
		return implode("\n", $lines);
	}

	/**
		The bot was kicked / removed, or the group became a supergroup: stop writing there.
	*/
	protected static function isGone(Throwable $e): bool {
		if (!$e instanceof TelegramError) {
			return false;
		}
		$message = mb_strtolower($e->getMessage());
		return $e->getCode() === 403
			|| str_contains($message, 'chat not found')
			|| str_contains($message, 'upgraded to a supergroup');
	}

	protected function sendOwnReminder(Tournament $t, int $minutes): void {
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
