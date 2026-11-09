<?php

declare(strict_types=1);

namespace P1Bot\View;

use P1Bot\Domain\Levels;
use P1Bot\Domain\Model\Balance;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Support\RuPlural;

/** Draft card shown to the organizer before publishing (HTML). */
final class TournamentCard
{
    public const QUESTION = 'Публикуем или что-то поправить?';

    public static function render(Tournament $t, ?Player $organizer, ?Balance $balance): string
    {
        $lines = [
            '📝 ' . Html::b('Турнир' . ($t->number !== null ? ' №' . $t->number : '') . ' (черновик)'),
            '🏆 Название: ' . Html::e($t->title),
            AnnouncementView::when($t),
            '👥 Команды по ' . $t->teamSize . ' ' . RuPlural::choose($t->teamSize, ['игроку', 'игрока', 'игроков']),
        ];
        if ($t->maxPoints !== null) {
            $lines[] = '⚖️ Уровень турнира: ' . Html::e($t->levelLabel());
            if ($t->referenceTeam !== null) {
                $levels = array_map('intval', explode(',', $t->referenceTeam));
                $lines[] = '📊 По игрокам: ' . Html::e(Levels::describeReferenceTeam($levels));
            }
        } else {
            $lines[] = '⚖️ Уровень турнира: не задан';
        }
        if ($balance !== null) {
            $lines[] = '📏 Шкала уровней: ' . Html::e($balance->name);
        }
        $lines[] = '🎟 Лимит участников: ' . $t->maxPlayers;
        if ($organizer !== null) {
            $lines[] = '👤 Организатор: ' . Html::e($organizer->mention());
        }
        return implode("\n", $lines) . "\n\n" . self::QUESTION;
    }

    /** Card after publishing: same facts, no question. */
    public static function published(string $cardHtml): string
    {
        $text = str_replace("\n\n" . self::QUESTION, '', $cardHtml);
        $text = str_replace(' (черновик)', '', $text);
        return $text . "\n\n✅ Опубликовано, регистрация открыта.";
    }

    /** @return array<string, mixed> */
    public static function buttons(int $tournamentId): array
    {
        return ['reply_markup' => ['inline_keyboard' => [[
            ['text' => '✅ Публикуем', 'callback_data' => 'pub:' . $tournamentId],
            ['text' => '✏️ Поправить', 'callback_data' => 'fix:' . $tournamentId],
        ]]]];
    }
}
