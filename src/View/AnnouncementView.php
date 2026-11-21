<?php

declare(strict_types=1);

namespace P1Bot\View;

use P1Bot\Domain\Levels;
use P1Bot\Domain\Lineup;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\Model\SoloPlayer;
use P1Bot\Domain\Model\TeamEntry;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\MoscowTime;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Support\RuPlural;
use P1Bot\Support\TeamLetters;

/**
	Tournament block (HTML): header with icons + teams, solo players, reserve and forming teams.
	One rendering for the announcement, /tournaments, /info and the AI list. Each team is two lines:
	letter, name, status, total points («N из LIMIT» when the tournament has a sum limit) and
	«⚠️ выше уровня» when over the limit; then the players, captain first with a crown.
	Individual player levels are never shown.
*/
class AnnouncementView {
	/**
		Telegram allows 4096 characters per message; keep a margin.
	*/
	public const MESSAGE_LIMIT = 4000;

	protected const STATUS_ICONS = [
		TournamentStatus::DRAFT => '📝',
		TournamentStatus::REGISTRATION => '🟢',
		TournamentStatus::CLOSED => '🔒',
		TournamentStatus::RUNNING => '🎮',
		TournamentStatus::FINISHED => '🏁',
		TournamentStatus::CANCELLED => '❌',
	];

	/**
		Solo players per line, so long lists can be cut between lines.
	*/
	protected const SOLO_PER_LINE = 8;

	public const OVER_LIMIT = '⚠️ выше уровня';

	/**
		@param array<int, Player> $players player_id => player, for everyone in the lineup and the organizer
		@param array<int, int> $levels player_id => effective level (missing = Levels::DEFAULT)
	*/
	public function __construct(
		protected string $bot_username,
		protected array $players,
		protected array $levels = [],
	) {
	}

	/**
		The announcement in the chat.

		@param bool|null $registration_open null = derive from status; false hides the sign-up footer
		(e.g. when the registration time lock has already kicked in).
	*/
	public function render(Tournament $t, Lineup $lineup, ?bool $registration_open = null): string {
		$open = $registration_open ?? ($t->status === TournamentStatus::REGISTRATION);
		$footer = $open
			? 'Записаться командой или соло, задать вопрос — в личке у @' . Html::e($this->bot_username) . ': жми кнопку ниже 👇'
			: '';
		return $this->compose($t, $lineup, $footer, self::MESSAGE_LIMIT, false, $open);
	}

	/**
		Block for /tournaments and the AI list. Too long lineups are cut with a hint to /info N.
	*/
	public function listBlock(
		Tournament $t,
		Lineup $lineup,
		int $limit = self::MESSAGE_LIMIT,
		string $footer = '',
	): string {
		return $this->compose($t, $lineup, $footer, $limit, true);
	}

	/**
		The whole lineup without cutting, split into several messages when needed (for /info).

		@return list<string>
	*/
	public function fullMessages(Tournament $t, Lineup $lineup, int $limit = self::MESSAGE_LIMIT): array {
		$sections = $this->sections($lineup, $t);
		if ($sections === []) {
			$sections = [['<i>Пока никто не записался.</i>']];
		}
		$messages = [];
		$current = $this->header($t, $lineup);
		foreach ($sections as $section) {
			foreach ($section as $j => $line) {
				$glue = $j === 0 ? "\n\n" : "\n";
				if (mb_strlen($current . $glue . $line) > $limit) {
					$messages[] = $current;
					$current = $line;
					continue;
				}
				$current .= $glue . $line;
			}
		}
		$messages[] = $current;
		return $messages;
	}

	/**
		@param bool|null $registration_open when false and status is still registration, show as closed
	*/
	public function header(Tournament $t, Lineup $lineup, ?bool $registration_open = null): string {
		$status = $t->status;
		if ($registration_open === false && $status === TournamentStatus::REGISTRATION) {
			$status = TournamentStatus::CLOSED;
		}
		$lines = [
			'🏆 ' . Html::b($t->title) . ($t->number !== null ? ' · №' . $t->number : ''),
			self::when($t),
			'👤 Организатор: ' . $this->name($t->organizer_id),
			'⚖️ Уровень: ' . Html::e($t->levelLabel()),
			'👥 Команды по ' . $t->team_size . ' ' . RuPlural::choose($t->team_size, ['игроку', 'игрока', 'игроков']),
			(self::STATUS_ICONS[$status] ?? '📌') . ' ' . Html::e(self::ucfirst(TournamentStatus::label($status))),
			'🎟 Занято ' . $lineup->occupied() . '/' . $lineup->max_players . ' · запас ' . $lineup->reserveCount(),
		];
		return implode("\n", $lines);
	}

	/**
		"🗓 23.11.2026 ⏰ 20:00 МСК"
	*/
	public static function when(Tournament $t): string {
		$msk = $t->starts_at->setTimezone(MoscowTime::zone());
		return '🗓 ' . $msk->format('d.m.Y') . ' ⏰ ' . $msk->format('H:i') . ' МСК';
	}

	/**
		Main, solo, reserve and forming lists.
	*/
	public function lineupText(Lineup $lineup, Tournament $t): string {
		return $this->join($this->sections($lineup, $t));
	}

	protected function compose(
		Tournament $t,
		Lineup $lineup,
		string $footer,
		int $limit,
		bool $empty_hint,
		?bool $registration_open = null,
	): string {
		$head = $this->header($t, $lineup, $registration_open);
		$sections = $this->sections($lineup, $t);
		if ($sections === [] && $empty_hint) {
			$sections = [['<i>Пока никто не записался.</i>']];
		}
		$tail = $footer !== '' ? "\n\n" . $footer : '';

		$full = $head . ($sections !== [] ? "\n\n" . $this->join($sections) : '') . $tail;
		if (mb_strlen($full) <= $limit) {
			return $full;
		}

		// Too long: keep whole cut-units (a team is two lines) while they fit, then say
		// how many visual lines were left out.
		$units = static fn (string $line): int => substr_count($line, "\n") + 1;
		$total = 0;
		foreach ($sections as $section) {
			foreach ($section as $line) {
				$total += $units($line);
			}
		}
		$body = '';
		$shown = 0;
		foreach ($sections as $i => $section) {
			foreach ($section as $j => $line) {
				$next = $body . ($body === '' ? '' : ($j === 0 ? "\n\n" : "\n")) . $line;
				$unit = $units($line);
				$hint = self::cutHint($total - $shown - $unit, $t->number);
				if (mb_strlen($head . "\n\n" . $next . "\n" . $hint . $tail) > $limit) {
					break 2;
				}
				$body = $next;
				$shown += $unit;
			}
		}
		return $head . "\n\n" . ($body !== '' ? $body . "\n" : '') . self::cutHint($total - $shown, $t->number) . $tail;
	}

	protected static function cutHint(int $left, ?int $number): string {
		$more = sprintf('…и ещё %d %s.', $left, RuPlural::choose($left, ['строка', 'строки', 'строк']));
		return '<i>' . $more . ($number !== null ? ' Полный состав: /info ' . $number : '') . '</i>';
	}

	/**
		@param list<list<string>> $sections
	*/
	protected function join(array $sections): string {
		return implode("\n\n", array_map(static function (array $s): string {
			return implode("\n", $s);
		}, array_values(array_filter($sections, static function (array $s): bool {
			return $s !== [];
		}))));
	}

	/**
		@return list<list<string>> sections, each a list of lines (cut points)
	*/
	protected function sections(Lineup $lineup, Tournament $t): array {
		$sections = [];
		if ($lineup->main_teams !== []) {
			$sections[] = array_merge(['🛡 <b>Команды:</b>'], $this->teams($lineup->main_teams, $t, 'записана'));
		}
		if ($lineup->main_solo !== []) {
			// In a 1-player format nobody looks for a team: solos already play on their own.
			$title = $t->team_size === 1 ? '🙋 <b>Соло:</b>' : '🙋 <b>Ищут команду:</b>';
			$sections[] = array_merge([$title], $this->solos($lineup->main_solo));
		}
		if ($lineup->reserve_teams !== [] || $lineup->reserve_solo !== []) {
			$reserve = ['⏳ <b>Запас:</b>'];
			$reserve = array_merge($reserve, $this->teams($lineup->reserve_teams, $t, 'запас'));
			foreach ($this->solos($lineup->reserve_solo) as $i => $line) {
				$reserve[] = ($i === 0 ? '🙋 Соло: ' : '') . $line;
			}
			$sections[] = $reserve;
		}
		if ($lineup->forming_teams !== []) {
			$forming = ['🧩 <b>Собираются:</b>'];
			foreach ($lineup->forming_teams as $e) {
				$forming[] = $this->teamBlock($e, $t, 'собирается ' . count($e->members) . '/' . $t->team_size);
			}
			$sections[] = $forming;
		}
		return $sections;
	}

	/**
		@param list<TeamEntry> $teams
		@return list<string> each item is two lines (kept together when a long message is cut)
	*/
	protected function teams(array $teams, Tournament $t, string $status): array {
		$rows = [];
		foreach ($teams as $entry) {
			$rows[] = $this->teamBlock($entry, $t, $status);
		}
		return $rows;
	}

	protected function teamBlock(TeamEntry $entry, Tournament $t, string $status): string {
		$points = $this->pointsOf($entry);
		$limit = $t->hasLevelLimit() ? (int) $t->max_points : null;
		$over = $limit !== null && $points > $limit;
		$name = $entry->team->isRegistered() ? Html::b($entry->team->name) : Html::e($entry->team->name);
		$bits = [
			TeamLetters::stored($entry->team->letter) . $name,
			Html::e($status),
			Html::e(self::pointsLabel($points, $limit, $over)),
		];
		return implode(' · ', $bits) . "\n" . $this->members($entry);
	}

	/**
		«11 из 10 ⚠️ выше уровня», or just «11» when the tournament has no sum limit.
	*/
	public static function pointsLabel(int $points, ?int $limit, bool $over): string {
		$text = $limit !== null ? $points . ' из ' . $limit : (string) $points;
		if ($over) {
			$text .= ' ' . self::OVER_LIMIT;
		}
		return $text;
	}

	protected function pointsOf(TeamEntry $entry): int {
		$sum = 0;
		foreach ($entry->members as $member) {
			$sum += $this->levels[$member->player_id] ?? Levels::DEFAULT;
		}
		return $sum;
	}

	protected function members(TeamEntry $entry): string {
		$captain = [];
		$others = [];
		foreach ($entry->members as $member) {
			$name = $this->name($member->player_id);
			if ($member->player_id === $entry->team->captain_id) {
				$captain[] = '👑 ' . $name;
			} else {
				$others[] = $name;
			}
		}
		return implode(', ', array_merge($captain, $others));
	}

	/**
		@param list<SoloPlayer> $solos
		@return list<string>
	*/
	protected function solos(array $solos): array {
		$names = array_map(function (SoloPlayer $s): string {
			return $this->name($s->player_id);
		}, $solos);
		return array_map(static function (array $chunk): string {
			return implode(', ', $chunk);
		}, array_chunk($names, self::SOLO_PER_LINE));
	}

	protected function name(int $player_id): string {
		$player = $this->players[$player_id] ?? null;
		if ($player === null) {
			return Html::e('игрок #' . $player_id);
		}
		// IGN as tg:// link when set; otherwise @username / name. No @ beside IGN in dense lineups.
		return $player->lineupLabel();
	}

	protected static function ucfirst(string $s): string {
		return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
	}
}
