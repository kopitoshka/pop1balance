<?php

declare(strict_types=1);

namespace P1Bot\View;

use P1Bot\Domain\Levels;
use P1Bot\Domain\Model\Balance;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Support\RuPlural;

/**
	Draft card shown to the organizer before publishing (HTML).
*/
class TournamentCard {
	public const QUESTION = 'Публикуем или что-то поправить?';

	public static function render(Tournament $t, ?Player $organizer, ?Balance $balance): string {
		$lines = [
			'📝 ' . Html::b('Турнир' . ($t->number !== null ? ' №' . $t->number : '') . ' (черновик)'),
			'🏆 Название: ' . Html::e($t->title),
			AnnouncementView::when($t),
			'👥 Команды по ' . $t->team_size . ' ' . RuPlural::choose($t->team_size, ['игроку', 'игрока', 'игроков']),
		];
		if ($t->max_points !== null) {
			$lines[] = '⚖️ Уровень турнира: ' . Html::e($t->levelLabel());
			if ($t->reference_team !== null) {
				$levels = array_map('intval', explode(',', $t->reference_team));
				$lines[] = '📊 По игрокам: ' . Html::e(Levels::describeReferenceTeam($levels));
			}
		} else {
			$lines[] = '⚖️ Уровень турнира: не задан';
		}
		// $balance is not shown for now: scales are hidden from users (see AccessPolicy::LEVELS_OWNER_ONLY).
		$lines[] = '🎟 Лимит участников: ' . $t->max_players;
		if ($organizer !== null) {
			$lines[] = '👤 Организатор: ' . Html::e($organizer->mention());
		}
		return implode("\n", $lines) . "\n\n" . self::QUESTION;
	}

	/**
		Card after publishing: same facts, no question.
	*/
	public static function published(string $card_html): string {
		$text = str_replace("\n\n" . self::QUESTION, '', $card_html);
		$text = str_replace(' (черновик)', '', $text);
		return $text . "\n\n✅ Опубликовано, регистрация открыта.";
	}

	/**
		@return array<string, mixed>
	*/
	public static function buttons(int $tournament_id): array {
		return ['reply_markup' => ['inline_keyboard' => [
			[
				['text' => '✅ Публикуем', 'callback_data' => 'pub:' . $tournament_id],
				['text' => '✏️ Поправить', 'callback_data' => 'fix:' . $tournament_id],
			],
			[
				['text' => '🗑 Удалить', 'callback_data' => 'ddel:' . $tournament_id],
			],
		]]];
	}
}
