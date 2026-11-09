<?php

declare(strict_types=1);

namespace P1Bot\Handlers;

use P1Bot\App\Announcer;
use P1Bot\App\DraftCards;
use P1Bot\Domain\Exception\DomainError;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\TeamService;
use P1Bot\Domain\TournamentService;
use P1Bot\Telegram\BotIdentity;
use P1Bot\Telegram\CallbackQuery;
use P1Bot\Telegram\TelegramApi;

/**
 * Inline buttons: under the announcement solo:<id>, unsolo:<id>, howto:<id>;
 * under the draft card pub:<id> («Публикуем»), fix:<id> («Поправить»).
 */
final class CallbackHandler
{
    public function __construct(
        private TelegramApi $api,
        private BotIdentity $bot,
        private TournamentService $tournaments,
        private TeamService $teams,
        private Announcer $announcer,
        private DraftCards $drafts,
    ) {
    }

    public function handle(CallbackQuery $query, Player $player): void
    {
        if (!preg_match('/^(solo|unsolo|howto|pub|fix):(\d+)$/', $query->data, $m)) {
            $this->api->answerCallbackQuery($query->id);
            return;
        }
        $tournamentId = (int) $m[2];

        try {
            $t = $this->tournaments->get($tournamentId);
            if ($t->chatId !== $query->chatId) {
                $this->api->answerCallbackQuery($query->id);
                return;
            }
            switch ($m[1]) {
                case 'pub':
                    $this->drafts->publish($player->id, $t->id, $query->messageId);
                    $this->api->answerCallbackQuery($query->id, 'Опубликовано! Анонс уже в чате.');
                    return;
                case 'fix':
                    if (!$this->drafts->canConfirm($t, $player->id)) {
                        $this->api->answerCallbackQuery($query->id, 'Править черновик может только его организатор или тот, кто попросил показать карточку.', true);
                        return;
                    }
                    $this->api->answerCallbackQuery($query->id, 'Ответь на карточку, что поправить: например «время 21:00» или «эталон средний, средний+, высокий».', true);
                    return;
                case 'solo':
                    $this->teams->joinSolo($t->id, $player->id);
                    $placement = $this->tournaments->lineup($t->id)->placementOf($player->id);
                    $text = $placement === 'reserve'
                        ? 'Записал тебя соло, но мест уже нет: ты в резерве. Если кто-то снимется, поднимешься сам.'
                        : 'Записал тебя соло! Команду соберёте уже в игре.';
                    $this->announcer->refresh($t->id);
                    $this->api->answerCallbackQuery($query->id, $text, true);
                    return;
                case 'unsolo':
                    $this->teams->leaveSolo($t->id, $player->id);
                    $this->announcer->refresh($t->id);
                    $this->api->answerCallbackQuery($query->id, 'Снял тебя с турнира. Передумаешь — жми «Хочу участвовать».', true);
                    return;
                default:
                    $this->api->answerCallbackQuery($query->id, sprintf(
                        'Капитан пишет в чат: @%s запиши команду <название>: я, @игрок1, @игрок2. В команде %d игрока(ов).',
                        $this->bot->username,
                        $t->teamSize,
                    ), true);
            }
        } catch (DomainError $e) {
            $this->api->answerCallbackQuery($query->id, $e->getMessage(), true);
        }
    }
}
