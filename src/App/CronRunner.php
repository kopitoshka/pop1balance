<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\MoscowTime;
use P1Bot\Domain\TournamentService;
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

	Tournaments are global. With REMIND_ALL_GROUPS=1 (default) every reminder mark
	(REMINDER_MINUTES, e.g. 60 and 15) posts the short notice with the deep-link button
	into every active group the bot is in. group_notices (tournament, chat, minutes) ensures
	each group gets at most one message per mark. REMIND_ALL_GROUPS=0 turns group broadcasts off.
	There is no private DM reminder path yet.
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
		foreach ($changed as $t) {
			$this->announcer->refresh($t->id);
		}
		if ($changed !== []) {
			$this->announcer->renumber();
		}

		return [
			'reminders' => $reminders,
			'status_changes' => count($changed),
			'conversations_deleted' => $this->conversations->deleteExpired($this->clock->now()),
		];
	}

	protected function remind(Tournament $t, int $minutes): void {
		if (!$this->remind_all_groups || $this->chats === null || $this->notices === null) {
			return;
		}
		$this->notifyAllGroups($t, $minutes);
	}

	/**
		Short notice for every active group; failures are logged and skipped,
		a group the bot was removed from is marked inactive.
	*/
	protected function notifyAllGroups(Tournament $t, int $minutes): void {
		$text = $this->groupNotice($t, $minutes);
		$markup = DeepLink::button(self::GROUP_BUTTON, DeepLink::tournament($this->bot_username, $t->id));
		$sent = 0;
		foreach (array_keys($this->chats->listActiveGroups()) as $chat_id) {
			if (!$this->notices->claim($t->id, $chat_id, $this->clock->now(), $minutes)) {
				continue;
			}
			try {
				$this->api->sendMessage($chat_id, $text, null, $markup);
				$this->notices->setResult($t->id, $chat_id, 'sent', $minutes);
				$sent++;
			} catch (Throwable $e) {
				$this->notices->setResult($t->id, $chat_id, 'error: ' . $e->getMessage(), $minutes);
				if (self::isGone($e)) {
					$this->chats->deactivate($chat_id);
					$this->logger->info(sprintf(
						'group notice %d: bot is not in group %d any more, marked inactive',
						$t->id,
						$chat_id,
					));
				} else {
					$this->logger->exception($e, sprintf('group notice %d to %d', $t->id, $chat_id));
				}
			}
		}
		$this->logger->info(sprintf(
			'group notice %d «%s» −%d min: sent to %d group(s)',
			$t->id,
			$t->title,
			$minutes,
			$sent,
		));
	}

	/**
		«Tournament in an hour / in N minutes» for every active group (HTML).
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
}
