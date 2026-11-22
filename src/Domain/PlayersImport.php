<?php

declare(strict_types=1);

namespace P1Bot\Domain;

use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Model\Player;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Database;
use P1Bot\Support\RuPlural;
use P1Bot\View\Html;

/**
	Owner-only bulk add/update of players and their levels (/players_import).
	New players follow /addplayer: @username is username-only, a plain line is nick-only.
	A line error is reported and does not roll back the rest; the whole apply is one transaction.
*/
class PlayersImport {
	public const MAX_LINES = 300;
	public const WAIT_TTL_SECONDS = 1800;
	public const OP = 'players_import';

	public const TOO_MANY = 'Слишком много строк: максимум %d. Сейчас %d. Разбей список на несколько сообщений.';
	public const EMPTY = 'Не вижу ни одной строки. Пришли список: на каждой строке игровой ник или @username, в конце можно уровень 1–6.';
	public const PROMPT = 'Пришли список игроков: каждая строка — игровой ник или @username, в конце можно поставить уровень от 1 до 6. Пустые строки пропускаю. Новый игрок без уровня получит БТР (3).';
	public const CANCEL_BUTTON = '✖️ Отмена';

	public function __construct(
		protected NickService $nicks,
		protected PlayerRepository $players,
		protected BalanceService $balances,
		protected AccessPolicy $access,
		protected Database $db,
	) {
	}

	/**
		Parse one message into rows (empty lines dropped). $raw is the trimmed source line.
		When the last token is all digits: 1–6 is the level, anything else is a line error.
		Otherwise the whole line is the nick or @username.

		@return list<array{raw: string, username: ?string, nick: ?string, level: ?int, error: ?string, key: ?string}>
	*/
	public static function parse(string $text): array {
		$out = [];
		foreach (preg_split('/\R/u', $text) ?: [] as $line) {
			$raw = trim($line);
			if ($raw === '') {
				continue;
			}
			$out[] = self::parseLine($raw);
		}
		return $out;
	}

	/**
		@param array{raw: string, username: ?string, nick: ?string, level: ?int, error: ?string, key: ?string} $row
		@return array{raw: string, username: ?string, nick: ?string, level: ?int, error: ?string, key: ?string}
	*/
	protected static function parseLine(string $raw): array {
		$empty = [
			'raw' => $raw,
			'username' => null,
			'nick' => null,
			'level' => null,
			'error' => null,
			'key' => null,
		];
		$tokens = preg_split('/\s+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$level = null;
		if ($tokens !== [] && preg_match('/^\d+$/D', (string) end($tokens))) {
			$n = (int) end($tokens);
			if (!Levels::isValid($n)) {
				array_pop($tokens);
				$name = implode(' ', $tokens);
				$row = $empty;
				$row['error'] = sprintf('уровень должен быть от %d до %d, а указан %d.', Levels::MIN, Levels::MAX, $n);
				$row['key'] = self::keyFromName($name);
				return $row;
			}
			$level = $n;
			array_pop($tokens);
		}
		$name = implode(' ', $tokens);
		if ($name === '') {
			$empty['error'] = 'нет ника и нет @username.';
			return $empty;
		}
		if (str_starts_with($name, '@')) {
			if (count($tokens) !== 1) {
				$empty['error'] = 'в строке с @username кроме уровня ничего больше быть не должно.';
				return $empty;
			}
			$username = PlayerRepository::normalizeUsername($name);
			if (!preg_match('/^[a-z][a-z0-9_]{4,31}$/D', $username)) {
				$empty['error'] = 'username: 5–32 символа, латинские буквы, цифры и _, первая — буква.';
				return $empty;
			}
			return [
				'raw' => $raw,
				'username' => $username,
				'nick' => null,
				'level' => $level,
				'error' => null,
				'key' => 'u:' . $username,
			];
		}
		try {
			[$ign] = NickService::parse($name);
		} catch (ValidationError $e) {
			$empty['error'] = rtrim($e->getMessage(), '.') . '.';
			$empty['key'] = 'n:' . mb_strtolower($name, 'UTF-8');
			return $empty;
		}
		return [
			'raw' => $raw,
			'username' => null,
			'nick' => $ign,
			'level' => $level,
			'error' => null,
			'key' => 'n:' . mb_strtolower($ign, 'UTF-8'),
		];
	}

	protected static function keyFromName(string $name): ?string {
		if ($name === '') {
			return null;
		}
		if (str_starts_with($name, '@')) {
			$username = PlayerRepository::normalizeUsername($name);
			return $username === '' ? null : 'u:' . $username;
		}
		try {
			[, $norm] = NickService::parse($name);
			return 'n:' . $norm;
		} catch (ValidationError $e) {
			unset($e);
			return 'n:' . mb_strtolower($name, 'UTF-8');
		}
	}

	/**
		Last occurrence of the same player wins (by @username or by in-game nick, case-insensitive).
		Error lines with a key replace an earlier successful line for that player.

		@param list<array{raw: string, username: ?string, nick: ?string, level: ?int, error: ?string, key: ?string}> $rows
		@return list<array{raw: string, username: ?string, nick: ?string, level: ?int, error: ?string, key: ?string}>
	*/
	public static function lastWins(array $rows): array {
		$kept = [];
		$index = [];
		foreach ($rows as $row) {
			$key = $row['key'];
			if ($key === null) {
				$kept[] = $row;
				continue;
			}
			if (isset($index[$key])) {
				$kept[$index[$key]] = $row;
				continue;
			}
			$index[$key] = count($kept);
			$kept[] = $row;
		}
		return array_values($kept);
	}

	/**
		@return array{
			added: list<array{player: Player, level: int}>,
			updated: list<array{player: Player, old_level: int, new_level: int}>,
			skipped: int,
			errors: list<array{raw: string, reason: string}>
		}
	*/
	public function apply(int $actor_id, int $chat_id, string $text): array {
		if (!$this->access->isSuper($actor_id)) {
			throw new PermissionDenied(AccessPolicy::OWNER_ONLY);
		}
		$parsed = self::parse($text);
		if ($parsed === []) {
			throw new ValidationError(self::EMPTY);
		}
		if (count($parsed) > self::MAX_LINES) {
			throw new ValidationError(sprintf(self::TOO_MANY, self::MAX_LINES, count($parsed)));
		}
		$rows = self::lastWins($parsed);
		return $this->db->transactional(function () use ($actor_id, $chat_id, $rows): array {
			$added = [];
			$updated = [];
			$skipped = 0;
			$errors = [];
			$scale_id = $this->balances->viewScaleId();
			foreach ($rows as $row) {
				if ($row['error'] !== null) {
					$errors[] = ['raw' => $row['raw'], 'reason' => $row['error']];
					continue;
				}
				try {
					$result = $this->applyRow($actor_id, $chat_id, $scale_id, $row);
				} catch (ValidationError $e) {
					$errors[] = ['raw' => $row['raw'], 'reason' => rtrim($e->getMessage(), '.') . '.'];
					continue;
				}
				if ($result['kind'] === 'added') {
					$added[] = ['player' => $result['player'], 'level' => $result['level']];
				} elseif ($result['kind'] === 'updated') {
					$updated[] = [
						'player' => $result['player'],
						'old_level' => $result['old_level'],
						'new_level' => $result['level'],
					];
				} else {
					$skipped++;
				}
			}
			return [
				'added' => $added,
				'updated' => $updated,
				'skipped' => $skipped,
				'errors' => $errors,
			];
		});
	}

	/**
		@param array{raw: string, username: ?string, nick: ?string, level: ?int, error: ?string, key: ?string} $row
		@return array{kind: 'added'|'updated'|'skipped', player: Player, level: int, old_level?: int}
	*/
	protected function applyRow(int $actor_id, int $chat_id, ?int $scale_id, array $row): array {
		$player = $row['username'] !== null
			? $this->players->findByUsername($row['username'])
			: $this->nicks->findByIgn((string) $row['nick']);
		$level = $row['level'];
		if ($player === null) {
			$created = $this->nicks->addPlayer($actor_id, $row['username'], $row['nick']);
			$player = $created['player'];
			$set = $level ?? Levels::DEFAULT;
			$this->balances->setLevel($chat_id, $actor_id, $player->id, $set);
			return ['kind' => 'added', 'player' => $this->players->find($player->id) ?? $player, 'level' => $set];
		}
		if ($level === null) {
			return ['kind' => 'skipped', 'player' => $player, 'level' => $this->balances->levelOf($scale_id, $player->id)];
		}
		$old = $this->balances->levelOf($scale_id, $player->id);
		if ($old === $level) {
			return ['kind' => 'skipped', 'player' => $player, 'level' => $old];
		}
		$this->balances->setLevel($chat_id, $actor_id, $player->id, $level);
		return [
			'kind' => 'updated',
			'player' => $this->players->find($player->id) ?? $player,
			'level' => $level,
			'old_level' => $old,
		];
	}

	/**
		@param array{
			added: list<array{player: Player, level: int}>,
			updated: list<array{player: Player, old_level: int, new_level: int}>,
			skipped: int,
			errors: list<array{raw: string, reason: string}>
		} $result
	*/
	public static function format(array $result): string {
		$lines = ['📥 <b>Импорт игроков</b>'];
		if ($result['added'] !== []) {
			$lines[] = '';
			$lines[] = sprintf('<b>Добавила</b> (%d):', count($result['added']));
			foreach ($result['added'] as $row) {
				$lines[] = '• ' . self::playerLabel($row['player']) . ' — ' . self::levelLabel($row['level']);
			}
		}
		if ($result['updated'] !== []) {
			$lines[] = '';
			$lines[] = sprintf('<b>Обновила</b> (%d):', count($result['updated']));
			foreach ($result['updated'] as $row) {
				$lines[] = '• ' . self::playerLabel($row['player']) . ' — '
					. self::levelLabel($row['old_level']) . ' → ' . self::levelLabel($row['new_level']);
			}
		}
		if (
			$result['added'] === []
			&& $result['updated'] === []
			&& $result['skipped'] === 0
			&& $result['errors'] === []
		) {
			$lines[] = '';
			$lines[] = 'Ничего не изменила.';
		}
		$tail = [];
		if ($result['skipped'] > 0) {
			$tail[] = sprintf(
				'Пропустила %d %s (игрок уже есть и уровень не менялся).',
				$result['skipped'],
				RuPlural::choose($result['skipped'], ['строку', 'строки', 'строк']),
			);
		}
		if ($result['errors'] !== []) {
			$tail[] = sprintf('<b>Ошибки</b> (%d):', count($result['errors']));
			foreach ($result['errors'] as $err) {
				$tail[] = '• «' . Html::e($err['raw']) . '» — ' . Html::e($err['reason']);
			}
		}
		if ($tail !== []) {
			$lines[] = '';
			array_push($lines, ...$tail);
		}
		return implode("\n", $lines);
	}

	protected static function playerLabel(Player $player): string {
		return $player->link(Html::e($player->plainLabel()));
	}

	protected static function levelLabel(int $level): string {
		return Html::e(Levels::name($level) . ' (' . $level . ')');
	}
}
