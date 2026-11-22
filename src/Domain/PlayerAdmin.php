<?php

declare(strict_types=1);

namespace P1Bot\Domain;

use P1Bot\Domain\Exception\FieldError;
use P1Bot\Domain\Exception\NotFound;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Model\Player;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Clock;
use P1Bot\Support\Database;
use P1Bot\Support\Logger;

/**
	What the owner does on a player card (Mini App): edit nick, level, @username and preferred
	team name, merge a duplicate into another player, delete a player.

	Delete is a soft delete: the row stays for history (past teams show «удалённый игрок») but the
	tg id, @username, nick and name are released. It is refused while the player is still in play
	(an active team, captain of one, solo sign-up, organizer of a tournament, owner of a scale).

	Changing the @username of a card with a bound Telegram account detaches that account (tg id
	cleared) after an explicit confirmation: the @username is how the bot recognizes a person, and
	Telegram would overwrite a hand-edited one on the next message of the bound account anyway.
	The card keeps its nick, level and teams; whoever writes to the bot with the new @username
	claims it (PlayerRepository::ensureByTelegram).
*/
class PlayerAdmin {
	public const USERNAME_PATTERN = '/^[a-z][a-z0-9_]{4,31}$/D';

	public const UNKNOWN = 'Такого игрока я не знаю.';
	public const USERNAME_HINT = 'Username: 5–32 символа, латинские буквы, цифры и _, первая — буква. Например: @player.';

	public function __construct(
		protected PlayerRepository $players,
		protected NickService $nicks,
		protected BalanceService $balances,
		protected AccessPolicy $access,
		protected Database $db,
		protected Clock $clock,
		protected ?Logger $logger = null,
	) {
	}

	/**
		A live (not deleted) player or NotFound.
	*/
	public function get(int $player_id): Player {
		$player = $this->players->find($player_id);
		if ($player === null || $player->isDeleted()) {
			throw new NotFound(self::UNKNOWN);
		}
		return $player;
	}

	/**
		Saves the changed fields of the card. Keys of $form: ign (empty clears), level (1–6, null
		removes the rating), username (empty clears), preferred_team_name (empty clears),
		confirm_unbind (bool). A missing key leaves that field as it is. All wrong fields are
		reported together (FieldError); nothing is saved then.

		@param array<string, mixed> $form
	*/
	public function save(int $actor_id, int $player_id, array $form): Player {
		$this->access->assertSuper($actor_id);
		$player = $this->get($player_id);
		$changes = $this->validate($player, $form);
		return $this->db->transactional(function () use ($actor_id, $player, $changes): Player {
			if (array_key_exists('ign', $changes)) {
				$this->nicks->setByOwner($actor_id, $player->id, $changes['ign']);
			}
			if (array_key_exists('username', $changes)) {
				$this->players->setUsername($player->id, $changes['username']);
				if ($changes['unbind']) {
					$this->players->unbindTelegram($player->id);
				}
			}
			if (array_key_exists('level', $changes)) {
				$scale_id = $this->balances->viewScaleId();
				if ($changes['level'] === null) {
					if ($scale_id !== null) {
						$this->balances->resetLevel(0, $actor_id, $player->id, $scale_id);
					}
				} else {
					$this->balances->setLevel(0, $actor_id, $player->id, $changes['level']);
				}
			}
			if (array_key_exists('preferred_team_name', $changes)) {
				$this->players->setPreferredTeamName($player->id, $changes['preferred_team_name']);
			}
			$this->logger?->info(sprintf('player card saved id=%d by=%d fields=%s', $player->id, $actor_id, implode(',', array_keys($changes))));
			return $this->players->find($player->id) ?? $player;
		});
	}

	/**
		Normalized changes (only fields that differ), or FieldError with every wrong field.

		@param array<string, mixed> $form
		@return array<string, mixed> ign|username|level|preferred_team_name => new value, unbind => bool
	*/
	protected function validate(Player $player, array $form): array {
		$changes = ['unbind' => false];
		$errors = [];
		$final_ign = $player->hasIgn();
		$final_username = $player->username !== null && $player->username !== '';
		$final_tg = $player->tg_user_id !== null;

		if (array_key_exists('ign', $form)) {
			$raw = trim((string) ($form['ign'] ?? ''));
			if ($raw === '') {
				if ($player->hasIgn()) {
					$changes['ign'] = null;
					$final_ign = false;
				}
			} else {
				try {
					[$ign, $norm] = NickService::parse($raw);
					$holder = $this->players->findByIgnNorm($norm);
					if ($holder !== null && $holder->id !== $player->id) {
						$errors['ign'] = sprintf(
							'Ник «%s» уже у игрока %s. Если это дубль — объедини их.',
							$holder->ign,
							$holder->plainLabel(),
						);
					} elseif ($player->ign !== $ign) {
						$changes['ign'] = $ign;
						$final_ign = true;
					}
				} catch (ValidationError $e) {
					$errors['ign'] = $e->getMessage();
				}
			}
		}

		if (array_key_exists('username', $form)) {
			$username = PlayerRepository::normalizeUsername((string) ($form['username'] ?? ''));
			$current = $player->username !== null && $player->username !== '' ? $player->username : '';
			if ($username !== $current) {
				$bound = $player->tg_user_id !== null;
				if ($this->access->isBotOwner($player->id)) {
					$errors['username'] = 'У главного админа @username не меняю: он подтягивается из Telegram.';
				} elseif ($username === '') {
					if ($bound) {
						$errors['username'] = 'У игрока привязан Telegram, @username подтянется из него сам. '
							. 'Чтобы перепривязать карточку, укажи другой @username.';
					} else {
						$changes['username'] = null;
						$final_username = false;
					}
				} elseif (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
					$errors['username'] = self::USERNAME_HINT;
				} else {
					$holder = $this->players->findByUsername($username);
					if ($holder !== null && $holder->id !== $player->id) {
						$errors['username'] = sprintf(
							'@%s уже у игрока %s. Если это дубль — объедини их.',
							$username,
							$holder->plainLabel(),
						);
					} elseif ($bound && empty($form['confirm_unbind'])) {
						$errors['confirm_unbind'] = 'Чтобы поменять @username, подтверди: Telegram-аккаунт игрока отвяжется от карточки.';
					} else {
						$changes['username'] = $username;
						$changes['unbind'] = $bound;
						$final_username = true;
						$final_tg = $bound ? false : $final_tg;
					}
				}
			}
		}

		if (array_key_exists('level', $form)) {
			$level = $form['level'];
			$current = $this->balances->explicitLevel($this->balances->viewScaleId(), $player->id);
			if ($level === null || $level === '') {
				if ($current !== null) {
					$changes['level'] = null;
				}
			} elseif (!is_numeric($level) || !Levels::isValid((int) $level)) {
				$errors['level'] = 'Уровень — число от 1 до 6 или «нет».';
			} elseif ((int) $level !== $current) {
				$changes['level'] = (int) $level;
			}
		}

		if (array_key_exists('preferred_team_name', $form)) {
			$name = trim((string) preg_replace('/\s+/u', ' ', (string) ($form['preferred_team_name'] ?? '')));
			$current = $this->players->preferredTeamName($player->id) ?? '';
			if (mb_strlen($name) > TeamService::MAX_NAME_LENGTH) {
				$errors['preferred_team_name'] = sprintf('Название до %d символов.', TeamService::MAX_NAME_LENGTH);
			} elseif ($name !== $current) {
				$changes['preferred_team_name'] = $name === '' ? null : $name;
			}
		}

		// A card must stay findable: a nick, an @username or a Telegram account.
		if ($errors === [] && !$final_ign && !$final_username && !$final_tg) {
			$errors[array_key_exists('ign', $changes) ? 'ign' : 'username'] =
				'Без ника и @username игрока не найти. Оставь хотя бы одно.';
		}
		if ($errors !== []) {
			throw new FieldError($errors);
		}
		return $changes;
	}

	/**
		Why the player cannot be deleted right now (empty = can be).

		@return list<string>
	*/
	public function deleteBlockers(int $player_id): array {
		if ($this->access->isBotOwner($player_id)) {
			return ['это главный админ: его карточку удалять нельзя'];
		}
		$uses = $this->players->activeUses($player_id);
		$out = [];
		foreach ($uses['teams'] as $row) {
			$out[] = sprintf('в команде «%s», турнир №%d «%s»', $row['team'], $row['tournament_id'], $row['title']);
		}
		foreach ($uses['captain'] as $row) {
			$out[] = sprintf('капитан команды «%s», турнир №%d «%s»', $row['team'], $row['tournament_id'], $row['title']);
		}
		foreach ($uses['solo'] as $row) {
			$out[] = sprintf('записан без команды, турнир №%d «%s»', $row['tournament_id'], $row['title']);
		}
		foreach ($uses['organizer'] as $row) {
			$out[] = sprintf('организатор турнира №%d «%s»', $row['tournament_id'], $row['title']);
		}
		if ($uses['balances'] > 0) {
			$out[] = 'владеет шкалой уровней';
		}
		return $out;
	}

	/**
		Soft delete (see the class comment). Refused while deleteBlockers() is not empty.
	*/
	public function delete(int $actor_id, int $player_id): void {
		$this->access->assertSuper($actor_id);
		$player = $this->get($player_id);
		$this->db->transactional(function () use ($actor_id, $player): void {
			$blockers = $this->deleteBlockers($player->id);
			if ($blockers !== []) {
				throw new ValidationError(
					"Удалить нельзя, пока игрок ещё играет:\n• " . implode("\n• ", $blockers),
				);
			}
			$this->players->softDelete($player->id, $this->clock->now());
			$this->logger?->info(sprintf(
				'player deleted id=%d by=%d was=%s tg=%s',
				$player->id,
				$actor_id,
				$player->plainLabel(),
				$player->tg_user_id ?? '-',
			));
		});
	}

	/**
		Why $from cannot be merged into $into (null = can be).
	*/
	public function mergeProblem(Player $from, Player $into): ?string {
		if ($from->id === $into->id) {
			return 'Выбери другого игрока: сам в себя карточку не слить.';
		}
		if ($this->access->isBotOwner($from->id)) {
			return 'Карточку главного админа в другую не сливаю. Влей другую карточку в неё.';
		}
		if ($from->tg_user_id !== null && $into->tg_user_id !== null) {
			return 'У обоих привязан свой Telegram-аккаунт: для меня это разные люди, их не объединить.';
		}
		$shared = $this->players->sharedTournaments($from->id, $into->id);
		if ($shared !== []) {
			$parts = array_map(
				static fn (array $row): string => sprintf('№%d «%s»', $row['tournament_id'], $row['title']),
				array_slice($shared, 0, 3),
			);
			$more = count($shared) > 3 ? ' и других' : '';
			return 'Оба уже есть в турнире ' . implode(', ', $parts) . $more
				. ': в один турнир один человек не попадёт дважды. Сначала убери одного из них из состава.';
		}
		return null;
	}

	/**
		Folds the duplicate $from_id into the survivor $into_id (full merge, see
		PlayerRepository::absorb) and soft-deletes the duplicate.
	*/
	public function merge(int $actor_id, int $from_id, int $into_id): Player {
		$this->access->assertSuper($actor_id);
		$from = $this->get($from_id);
		$into = $this->get($into_id);
		return $this->db->transactional(function () use ($actor_id, $from, $into): Player {
			$problem = $this->mergeProblem($from, $into);
			if ($problem !== null) {
				throw new ValidationError($problem);
			}
			$this->players->absorb($into->id, $from->id, $this->clock->now());
			$this->logger?->info(sprintf(
				'players merged from=%d (%s) into=%d by=%d',
				$from->id,
				$from->plainLabel(),
				$into->id,
				$actor_id,
			));
			return $this->players->find($into->id) ?? $into;
		});
	}
}
