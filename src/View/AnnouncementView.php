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
	One rendering for the announcement, /tournaments, /tournaments N and the AI list. Each team is two lines:
	letter, name, strength as «lvl.N», and «⚠️ выше уровня» when over the limit; then the players,
	captain first with a crown. The main teams sit in one <blockquote>, separated by an empty line,
	without a «Команды» header. When the message lists more than one tournament the quote is
	<blockquote expandable>. Forming teams still show «собирается N/SIZE». Individual player
	levels are never shown.
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
		Block for /tournaments and the AI list. Too long lineups are cut with a hint to /tournaments N.
	*/
	public function listBlock(
		Tournament $t,
		Lineup $lineup,
		int $limit = self::MESSAGE_LIMIT,
		string $footer = '',
		bool $collapse_teams = false,
	): string {
		return $this->compose($t, $lineup, $footer, $limit, true, null, $collapse_teams);
	}

	/**
		The whole lineup without cutting, split into several messages when needed (for /tournaments N).

		@return list<string>
	*/
	public function fullMessages(Tournament $t, Lineup $lineup, int $limit = self::MESSAGE_LIMIT): array {
		$sections = $this->sections($lineup, $t);
		if ($sections === []) {
			$sections = [['kind' => 'plain', 'lines' => ['<i>Пока никто не записался.</i>']]];
		}
		$messages = [];
		$current = $this->header($t, $lineup);
		$open = '<blockquote>';
		foreach ($sections as $section) {
			if ($section['kind'] === 'quote') {
				$quote_open = false;
				foreach ($section['lines'] as $line) {
					if ($quote_open) {
						if (mb_strlen($current . "\n\n" . $line . '</blockquote>') > $limit) {
							$current .= '</blockquote>';
							$messages[] = $current;
							$current = $open . $line;
							continue;
						}
						$current .= "\n\n" . $line;
						continue;
					}
					$glue = $current === '' ? '' : "\n\n";
					if ($current !== '' && mb_strlen($current . $glue . $open . $line . '</blockquote>') > $limit) {
						$messages[] = $current;
						$current = $open . $line;
						$quote_open = true;
						continue;
					}
					$current .= $glue . $open . $line;
					$quote_open = true;
				}
				if ($quote_open) {
					$current .= '</blockquote>';
				}
				continue;
			}
			foreach ($section['lines'] as $j => $line) {
				$glue = $j === 0 ? "\n\n" : "\n";
				if ($current !== '' && mb_strlen($current . $glue . $line) > $limit) {
					$messages[] = $current;
					$current = $line;
					continue;
				}
				$current .= ($current === '' ? '' : $glue) . $line;
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
		];
		$map = self::mapLine($t);
		if ($map !== null) {
			$lines[] = $map;
		}
		$lines[] = '👤 Организатор: ' . $this->name($t->organizer_id);
		$lines[] = '⚖️ Уровень: ' . Html::e($t->levelLabel());
		$lines[] = '👥 Команды по ' . $t->team_size . ' ' . RuPlural::choose($t->team_size, ['игроку', 'игрока', 'игроков']);
		$lines[] = (self::STATUS_ICONS[$status] ?? '📌') . ' ' . Html::e(self::ucfirst(TournamentStatus::label($status)));
		$lines[] = '🎟 Занято ' . $lineup->occupied() . '/' . $lineup->max_players . ' · запас ' . $lineup->reserveCount();
		$text = implode("\n", $lines);
		$description = self::descriptionBlock($t);
		return $description === null ? $text : $text . "\n\n" . $description;
	}

	/**
		"🗺 Карта: Phoenix", or null when the tournament has no map (created before the field existed).
	*/
	public static function mapLine(Tournament $t): ?string {
		return $t->map !== null && $t->map !== '' ? '🗺 Карта: ' . Html::e($t->map) : null;
	}

	/**
		The optional description, escaped for Telegram HTML.
	*/
	public static function descriptionBlock(Tournament $t): ?string {
		$text = $t->description !== null ? trim($t->description) : '';
		return $text !== '' ? Html::e($text) : null;
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
		return $this->join($this->sections($lineup, $t), false);
	}

	protected function compose(
		Tournament $t,
		Lineup $lineup,
		string $footer,
		int $limit,
		bool $empty_hint,
		?bool $registration_open = null,
		bool $collapse_teams = false,
	): string {
		$head = $this->header($t, $lineup, $registration_open);
		$sections = $this->sections($lineup, $t);
		if ($sections === [] && $empty_hint) {
			$sections = [['kind' => 'plain', 'lines' => ['<i>Пока никто не записался.</i>']]];
		}
		$tail = $footer !== '' ? "\n\n" . $footer : '';

		$full = $head . ($sections !== [] ? "\n\n" . $this->join($sections, $collapse_teams) : '') . $tail;
		if (mb_strlen($full) <= $limit) {
			return $full;
		}

		// Too long: keep whole cut-units (a team is two lines) while they fit, then say
		// how many visual lines were left out. A blockquote is always closed before the hint.
		$units = static fn (string $line): int => substr_count($line, "\n") + 1;
		$total = 0;
		foreach ($sections as $section) {
			foreach ($section['lines'] as $line) {
				$total += $units($line);
			}
		}
		$kept = [];
		$shown = 0;
		foreach ($sections as $section) {
			$lines = [];
			$cut = false;
			foreach ($section['lines'] as $line) {
				$trial = $kept;
				$trial[] = ['kind' => $section['kind'], 'lines' => array_merge($lines, [$line])];
				$unit = $units($line);
				$hint = self::cutHint($total - $shown - $unit, $t->number);
				$body = $this->join($trial, $collapse_teams);
				if (mb_strlen($head . "\n\n" . $body . "\n" . $hint . $tail) > $limit) {
					$cut = true;
					break;
				}
				$lines[] = $line;
				$shown += $unit;
			}
			if ($lines !== []) {
				$kept[] = ['kind' => $section['kind'], 'lines' => $lines];
			}
			if ($cut) {
				break;
			}
		}
		$body = $this->join($kept, $collapse_teams);
		return $head . "\n\n" . ($body !== '' ? $body . "\n" : '') . self::cutHint($total - $shown, $t->number) . $tail;
	}

	protected static function cutHint(int $left, ?int $number): string {
		$more = sprintf('…и ещё %d %s.', $left, RuPlural::choose($left, ['строка', 'строки', 'строк']));
		return '<i>' . $more . ($number !== null ? ' Полный состав: /tournaments ' . $number : '') . '</i>';
	}

	/**
		@param list<array{kind: 'plain'|'quote', lines: list<string>}> $sections
	*/
	protected function join(array $sections, bool $collapse_teams): string {
		$parts = [];
		foreach ($sections as $section) {
			if ($section['lines'] === []) {
				continue;
			}
			$parts[] = $section['kind'] === 'quote'
				? self::quote($section['lines'], $collapse_teams)
				: implode("\n", $section['lines']);
		}
		return implode("\n\n", $parts);
	}

	/**
		@param list<string> $teams
	*/
	protected static function quote(array $teams, bool $expandable): string {
		$tag = $expandable ? '<blockquote expandable>' : '<blockquote>';
		return $tag . implode("\n\n", $teams) . '</blockquote>';
	}

	/**
		@return list<array{kind: 'plain'|'quote', lines: list<string>}> sections of cut-units
	*/
	protected function sections(Lineup $lineup, Tournament $t): array {
		$sections = [];
		if ($lineup->main_teams !== []) {
			$sections[] = ['kind' => 'quote', 'lines' => $this->teams(Lineup::sortByLetter($lineup->main_teams), $t)];
		}
		if ($lineup->main_solo !== []) {
			// In a 1-player format nobody looks for a team: solos already play on their own.
			$title = $t->team_size === 1 ? '🙋 <b>Соло:</b>' : '🙋 <b>Ищут команду:</b>';
			$sections[] = ['kind' => 'plain', 'lines' => array_merge([$title], $this->solos($lineup->main_solo))];
		}
		if ($lineup->reserve_teams !== [] || $lineup->reserve_solo !== []) {
			$reserve = ['⏳ <b>Запас:</b>'];
			$reserve = array_merge($reserve, $this->teams(Lineup::sortByLetter($lineup->reserve_teams), $t));
			foreach ($this->solos($lineup->reserve_solo) as $i => $line) {
				$reserve[] = ($i === 0 ? '🙋 Соло: ' : '') . $line;
			}
			$sections[] = ['kind' => 'plain', 'lines' => $reserve];
		}
		if ($lineup->forming_teams !== []) {
			$forming = ['🧩 <b>Собираются:</b>'];
			foreach (Lineup::sortByLetter($lineup->forming_teams) as $e) {
				$forming[] = $this->teamBlock($e, $t, 'собирается ' . count($e->members) . '/' . $t->team_size);
			}
			$sections[] = ['kind' => 'plain', 'lines' => $forming];
		}
		return $sections;
	}

	/**
		@param list<TeamEntry> $teams
		@return list<string> each item is two lines (kept together when a long message is cut)
	*/
	protected function teams(array $teams, Tournament $t, string $status = ''): array {
		$rows = [];
		foreach ($teams as $entry) {
			$rows[] = $this->teamBlock($entry, $t, $status);
		}
		return $rows;
	}

	protected function teamBlock(TeamEntry $entry, Tournament $t, string $status = ''): string {
		$points = $this->pointsOf($entry);
		$limit = $t->hasLevelLimit() ? (int) $t->max_points : null;
		$over = $limit !== null && $points > $limit;
		$name = $entry->team->isRegistered() ? Html::b($entry->team->name) : Html::e($entry->team->name);
		$bits = [TeamLetters::stored($entry->team->letter) . $name];
		if ($status !== '') {
			$bits[] = Html::e($status);
		}
		$bits[] = Html::e(self::pointsLabel($points, $limit, $over));
		return implode(' · ', $bits) . "\n" . $this->members($entry);
	}

	/**
		«lvl.11 ⚠️ выше уровня». $limit is kept for callers; the number itself is no longer shown.
	*/
	public static function pointsLabel(int $points, ?int $limit, bool $over): string {
		unset($limit);
		$text = 'lvl.' . $points;
		if ($over) {
			$text .= ' ' . self::OVER_LIMIT;
		}
		return $text;
	}

	protected function pointsOf(TeamEntry $entry): int {
		$levels = [];
		foreach ($entry->members as $member) {
			$levels[] = $this->levels[$member->player_id] ?? Levels::DEFAULT;
		}
		return Levels::teamPoints($levels);
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
