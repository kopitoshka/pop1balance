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

/** Runs every minute: reminders, automatic status changes, cleanup. */
final class CronRunner
{
    /**
     * @param list<int> $reminderMinutes
     */
    public function __construct(
        private TournamentService $tournaments,
        private ConversationRepository $conversations,
        private PlayerRepository $players,
        private Announcer $announcer,
        private TelegramApi $api,
        private Clock $clock,
        private Logger $logger,
        private array $reminderMinutes,
        private int $finishAfterHours,
    ) {
    }

    /** @return array{reminders: int, status_changes: int, conversations_deleted: int} */
    public function run(): array
    {
        $reminders = 0;
        foreach ($this->tournaments->dueReminders($this->reminderMinutes) as [$t, $minutes]) {
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

        $changed = $this->tournaments->autoAdvance($this->finishAfterHours);
        $chats = [];
        foreach ($changed as $t) {
            $this->announcer->refresh($t->id);
            $chats[$t->chatId] = true;
        }
        foreach (array_keys($chats) as $chatId) {
            // Finished tournaments drop out of the numbering: others may get new numbers.
            $this->announcer->renumber((int) $chatId);
        }

        return [
            'reminders' => $reminders,
            'status_changes' => count($changed),
            'conversations_deleted' => $this->conversations->deleteExpired($this->clock->now()),
        ];
    }

    private function remind(Tournament $t, int $minutes): void
    {
        $lineup = $this->tournaments->lineup($t->id);
        $mentions = [];
        foreach ($lineup->mainTeams as $entry) {
            foreach ($entry->members as $member) {
                $mentions[] = $this->mention($member->playerId);
            }
        }
        foreach ($lineup->mainSolo as $solo) {
            $mentions[] = $this->mention($solo->playerId);
        }

        $text = sprintf(
            '⏰ Через %d %s старт турнира %s (%s)!',
            $minutes,
            RuPlural::choose($minutes, ['минуту', 'минуты', 'минут']),
            Html::b($t->title),
            MoscowTime::format($t->startsAt, 'H:i'),
        );
        $text .= "\n" . Html::e(mb_strtoupper(mb_substr($lineup->summary(), 0, 1)) . mb_substr($lineup->summary(), 1)) . '.';
        if ($mentions !== []) {
            $text .= "\n\nИграют: " . implode(', ', $mentions) . "\nУдачи всем, заходите в игру заранее!";
        }
        if ($lineup->reserveCount() > 0) {
            $text .= "\nРезерв, будьте на связи: если кто-то не придёт, позовём вас.";
        }
        $this->api->sendMessage($t->chatId, $text, $t->announceMsgId);
    }

    private function mention(int $playerId): string
    {
        $player = $this->players->find($playerId);
        if (!$player instanceof Player) {
            return '#' . $playerId;
        }
        if ($player->username === null && $player->tgUserId !== null) {
            return '<a href="tg://user?id=' . $player->tgUserId . '">' . Html::e($player->displayName) . '</a>';
        }
        return Html::e($player->mention());
    }
}
