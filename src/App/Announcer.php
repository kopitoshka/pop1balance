<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Domain\Lineup;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\TournamentService;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Logger;
use P1Bot\Telegram\BotIdentity;
use P1Bot\Telegram\TelegramApi;
use P1Bot\Telegram\TelegramError;
use P1Bot\View\AnnouncementView;
use Throwable;

/** Posts the tournament announcement and keeps it up to date. */
final class Announcer
{
    public function __construct(
        private TelegramApi $api,
        private TournamentService $tournaments,
        private PlayerRepository $players,
        private BotIdentity $bot,
        private Logger $logger,
    ) {
    }

    /** Sends a new announcement and remembers its message id. Returns the message id. */
    public function publish(int $tournamentId): int
    {
        $t = $this->tournaments->get($tournamentId);
        $messageId = $this->api->sendMessage($t->chatId, $this->text($t), null, $this->markup($t));
        $this->tournaments->setAnnounceMessage($t->id, $messageId);
        return $messageId;
    }

    /** Re-renders the announcement after any change. Never throws. */
    public function refresh(int $tournamentId): void
    {
        try {
            $t = $this->tournaments->get($tournamentId);
            if ($t->announceMsgId === null) {
                return;
            }
            $this->api->editMessageText($t->chatId, $t->announceMsgId, $this->text($t), $this->markup($t));
        } catch (TelegramError $e) {
            if (!$e->isNotModified()) {
                $this->logger->exception($e, 'refresh announcement ' . $tournamentId);
            }
        } catch (Throwable $e) {
            $this->logger->exception($e, 'refresh announcement ' . $tournamentId);
        }
    }

    public function text(Tournament $t, ?Lineup $lineup = null): string
    {
        $lineup = $lineup ?? $this->tournaments->lineup($t->id);
        return $this->view($t, $lineup)->render($t, $lineup);
    }

    /** Lineup lists only (for /tournament info replies). */
    public function lineupText(Tournament $t): string
    {
        $lineup = $this->tournaments->lineup($t->id);
        return $this->view($t, $lineup)->lineupText($lineup);
    }

    /** @return array<string, mixed> */
    private function markup(Tournament $t): array
    {
        if ($t->status !== TournamentStatus::REGISTRATION) {
            return ['reply_markup' => ['inline_keyboard' => []]];
        }
        return ['reply_markup' => ['inline_keyboard' => [[
            ['text' => '🙋 Хочу участвовать', 'callback_data' => 'solo:' . $t->id],
            ['text' => 'Передумал', 'callback_data' => 'unsolo:' . $t->id],
        ], [
            ['text' => 'Как записать команду?', 'callback_data' => 'howto:' . $t->id],
        ]]]];
    }

    private function view(Tournament $t, Lineup $lineup): AnnouncementView
    {
        $ids = [$t->organizerId];
        foreach (array_merge($lineup->mainTeams, $lineup->reserveTeams, $lineup->formingTeams) as $entry) {
            foreach ($entry->members as $member) {
                $ids[] = $member->playerId;
            }
        }
        foreach (array_merge($lineup->mainSolo, $lineup->reserveSolo) as $solo) {
            $ids[] = $solo->playerId;
        }
        $players = [];
        foreach (array_unique($ids) as $id) {
            $player = $this->players->find($id);
            if ($player instanceof Player) {
                $players[$id] = $player;
            }
        }
        return new AnnouncementView($this->bot->username, $players);
    }
}
