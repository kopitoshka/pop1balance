<?php

declare(strict_types=1);

namespace P1Bot\View;

use P1Bot\Domain\Lineup;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\Model\SoloPlayer;
use P1Bot\Domain\Model\TeamEntry;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\MoscowTime;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Support\RuPlural;

/** Tournament announcement and lineup (HTML). */
final class AnnouncementView
{
    /**
     * @param array<int, Player> $players player_id => player, for everyone in the lineup and the organizer
     */
    public function __construct(
        private string $botUsername,
        private array $players,
    ) {
    }

    public function render(Tournament $t, Lineup $lineup): string
    {
        $lines = [
            '🏆 ' . Html::b($t->title) . ' (№' . $t->id . ')',
            '🕗 ' . MoscowTime::format($t->startsAt),
            '👤 Организатор: ' . $this->name($t->organizerId),
            '⚖️ Уровень: ' . Html::e($t->levelLabel()),
            '👥 Команды по ' . $t->teamSize . ' ' . RuPlural::choose($t->teamSize, ['игроку', 'игрока', 'игроков']),
            '📋 ' . Html::e(self::ucfirst($lineup->summary())),
        ];
        if ($t->status !== TournamentStatus::REGISTRATION) {
            $lines[] = '📌 ' . Html::e(self::ucfirst(TournamentStatus::label($t->status)));
        }

        $sections = [implode("\n", $lines), $this->lineupText($lineup)];
        if ($t->status === TournamentStatus::REGISTRATION) {
            $sections[] = 'Записаться командой или соло: напиши @' . Html::e($this->botUsername)
                . ' или нажми кнопку ниже.';
        }
        return implode("\n\n", array_filter($sections, static function (string $s): bool {
            return $s !== '';
        }));
    }

    /** Main, reserve, solo and forming lists. */
    public function lineupText(Lineup $lineup): string
    {
        $blocks = [];
        if ($lineup->mainTeams !== []) {
            $blocks[] = "<b>Команды:</b>\n" . $this->teams($lineup->mainTeams);
        }
        if ($lineup->mainSolo !== []) {
            $blocks[] = "<b>Соло, ищут команду:</b>\n" . $this->solos($lineup->mainSolo);
        }
        if ($lineup->reserveTeams !== [] || $lineup->reserveSolo !== []) {
            $reserve = [];
            if ($lineup->reserveTeams !== []) {
                $reserve[] = $this->teams($lineup->reserveTeams);
            }
            if ($lineup->reserveSolo !== []) {
                $reserve[] = 'Соло: ' . implode(', ', array_map(function (SoloPlayer $s): string {
                    return $this->name($s->playerId);
                }, $lineup->reserveSolo));
            }
            $blocks[] = "<b>Резерв:</b>\n" . implode("\n", $reserve);
        }
        if ($lineup->formingTeams !== []) {
            $blocks[] = "<b>Собираются:</b>\n" . implode("\n", array_map(function (TeamEntry $e): string {
                return '• ' . Html::e($e->team->name) . ' — ' . $this->members($e);
            }, $lineup->formingTeams));
        }
        return implode("\n\n", $blocks);
    }

    /** @param list<TeamEntry> $teams */
    private function teams(array $teams): string
    {
        $rows = [];
        foreach ($teams as $i => $entry) {
            $rows[] = ($i + 1) . '. ' . Html::b($entry->team->name) . ' — ' . $this->members($entry);
        }
        return implode("\n", $rows);
    }

    private function members(TeamEntry $entry): string
    {
        $names = [];
        foreach ($entry->members as $member) {
            $name = $this->name($member->playerId);
            $names[] = $member->playerId === $entry->team->captainId ? $name . ' (кэп)' : $name;
        }
        return implode(', ', $names);
    }

    /** @param list<SoloPlayer> $solos */
    private function solos(array $solos): string
    {
        return implode(', ', array_map(function (SoloPlayer $s): string {
            return $this->name($s->playerId);
        }, $solos));
    }

    private function name(int $playerId): string
    {
        $player = $this->players[$playerId] ?? null;
        return Html::e($player !== null ? $player->mention() : 'игрок #' . $playerId);
    }

    private static function ucfirst(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }
}
