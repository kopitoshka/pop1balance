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

/**
	Tournament block (HTML): header with icons + teams, solo players, reserve and forming teams.
	One rendering for the announcement, /tournaments, /info and the AI list. Player levels are never shown.
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

	/**
		@param array<int, Player> $players player_id => player, for everyone in the lineup and the organizer
	*/
	public function __construct(
		protected string $bot_username,
		protected array $players,
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
		$sections = $this->sections($lineup, $t->team_size);
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
	public function lineupText(Lineup $lineup, int $team_size): string {
		return $this->join($this->sections($lineup, $team_size));
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
		$sections = $this->sections($lineup, $t->team_size);
		if ($sections === [] && $empty_hint) {
			$sections = [['<i>Пока никто не записался.</i>']];
		}
		$tail = $footer !== '' ? "\n\n" . $footer : '';

		$full = $head . ($sections !== [] ? "\n\n" . $this->join($sections) : '') . $tail;
		if (mb_strlen($full) <= $limit) {
			return $full;
		}

		// Too long: keep whole lines while they fit, then say how many lines were left out.
		$total = array_sum(array_map('count', $sections));
		$body = '';
		$shown = 0;
		foreach ($sections as $i => $section) {
			foreach ($section as $j => $line) {
				$next = $body . ($body === '' ? '' : ($j === 0 ? "\n\n" : "\n")) . $line;
				$hint = self::cutHint($total - $shown - 1, $t->number);
				if (mb_strlen($head . "\n\n" . $next . "\n" . $hint . $tail) > $limit) {
					break 2;
				}
				$body = $next;
				$shown++;
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
	protected function sections(Lineup $lineup, int $team_size): array {
		$sections = [];
		if ($lineup->main_teams !== []) {
			$sections[] = array_merge(['🛡 <b>Команды:</b>'], $this->teams($lineup->main_teams));
		}
		if ($lineup->main_solo !== []) {
			$sections[] = array_merge(['🙋 <b>Ищут команду:</b>'], $this->solos($lineup->main_solo));
		}
		if ($lineup->reserve_teams !== [] || $lineup->reserve_solo !== []) {
			$reserve = ['⏳ <b>Запас:</b>'];
			$reserve = array_merge($reserve, $this->teams($lineup->reserve_teams));
			foreach ($this->solos($lineup->reserve_solo) as $i => $line) {
				$reserve[] = ($i === 0 ? '🙋 Соло: ' : '') . $line;
			}
			$sections[] = $reserve;
		}
		if ($lineup->forming_teams !== []) {
			$forming = ['🧩 <b>Собираются:</b>'];
			foreach ($lineup->forming_teams as $e) {
				$forming[] = '• ' . Html::e($e->team->name) . ' — ' . $this->members($e)
					. ' (' . count($e->members) . '/' . $team_size . ')';
			}
			$sections[] = $forming;
		}
		return $sections;
	}

	/**
		@param list<TeamEntry> $teams
		@return list<string>
	*/
	protected function teams(array $teams): array {
		$rows = [];
		foreach ($teams as $i => $entry) {
			$rows[] = ($i + 1) . '. ' . Html::b($entry->team->name) . ' — ' . $this->members($entry);
		}
		return $rows;
	}

	protected function members(TeamEntry $entry): string {
		$captain = [];
		$others = [];
		foreach ($entry->members as $member) {
			$name = $this->name($member->player_id);
			if ($member->player_id === $entry->team->captain_id) {
				$captain[] = '👑 ' . $name . ' (кэп)';
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
