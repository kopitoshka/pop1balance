<?php

declare(strict_types=1);

namespace P1Bot\Ai;

use P1Bot\App\Announcer;
use P1Bot\App\Confirmation;
use P1Bot\App\DraftCards;
use P1Bot\App\NeedGroupChoice;
use P1Bot\App\PrivateGroups;
use P1Bot\App\PlayerResolver;
use P1Bot\App\TournamentResolver;
use P1Bot\Domain\AccessPolicy;
use P1Bot\Domain\BalanceService;
use P1Bot\Domain\CreateTournament;
use P1Bot\Domain\EditTournament;
use P1Bot\Domain\Exception\DomainError;
use P1Bot\Domain\Exception\InvalidLevel;
use P1Bot\Domain\Exception\NotFound;
use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Levels;
use P1Bot\Domain\Model\TeamEntry;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\MoscowTime;
use P1Bot\Domain\TeamService;
use P1Bot\Domain\TournamentService;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Handlers\Context;
use P1Bot\Repository\BalanceRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Clock;
use P1Bot\Support\Logger;
use P1Bot\View\Messages;
use Throwable;

/**
	Executes model function calls through the domain services.
	The model never decides permissions: services and the admin check below do.
	Results are plain arrays sent back to the model as JSON.
*/
class Dispatcher {

	public function __construct(
		protected TournamentService $tournaments,
		protected TeamService $teams,
		protected BalanceService $balances,
		protected BalanceRepository $balance_repo,
		protected PlayerRepository $players,
		protected TournamentResolver $resolver,
		protected PlayerResolver $player_resolver,
		protected Announcer $announcer,
		protected Clock $clock,
		protected Logger $logger,
		protected ?DraftCards $drafts = null,
		protected string $bot_username = '',
		protected ?PrivateGroups $groups = null,
	) {
	}

	/**
		@param array<string, mixed> $args
		@return array<string, mixed>
	*/
	public function dispatch(string $tool, array $args, Context $ctx): array {
		try {
			if (in_array($tool, Tools::OWNER_TOOLS, true) && !$ctx->is_owner) {
				throw new PermissionDenied($tool === 'set_level' ? AccessPolicy::LEVELS_OWNER_ONLY : AccessPolicy::OWNER_ONLY);
			}
			if (in_array($tool, Tools::ADMIN_TOOLS, true) && !$ctx->is_admin) {
				throw new PermissionDenied('Это действие доступно только админам чата.');
			}
			$result = $this->run($tool, $args, $ctx);
			if (in_array($tool, Tools::MUTATING, true) && isset($result['tournament_id'])) {
				// tournament_id in results is the short number the user sees; map it back.
				$real_id = array_search(
					(int) $result['tournament_id'],
					$this->resolver->numbers($ctx->groupId()),
					true,
				);
				if ($real_id !== false) {
					$this->announcer->refresh((int) $real_id);
				}
			}
			return ['ok' => true] + $result;
		} catch (InvalidLevel $e) {
			return [
				'ok' => false,
				'error' => $e->getMessage(),
				'what_to_do' => 'Переспроси уровень турнира и покажи примеры: одно число — сумма баллов на команду (например «10» для троек), '
					. 'или уровни всех игроков через запятую по числу игроков («средний, средний, высокий»). Все уровни: /levels.',
			];
		} catch (NeedGroupChoice $e) {
			return [
				'ok' => false,
				'error' => $e->getMessage(),
				'groups' => array_values($e->groups),
				'choose_group_ids' => $e->groups,
				'note' => 'Кнопки выбора группы покажутся автоматически. Попроси выбрать группу и повторить запрос.',
			];
		} catch (DomainError $e) {
			return ['ok' => false, 'error' => $e->getMessage()];
		} catch (Throwable $e) {
			$this->logger->exception($e, 'tool ' . $tool);
			return ['ok' => false, 'error' => 'Внутренняя ошибка, действие не выполнено.'];
		}
	}

	/**
		@param array<string, mixed> $a
		@return array<string, mixed>
	*/
	protected function run(string $tool, array $a, Context $ctx): array {
		$actor = $ctx->actor;
		switch ($tool) {
			case 'list_tournaments':
				$active = $this->tournaments->listActive($ctx->groupId());
				if ($active === []) {
					return ['tournaments' => []];
				}
				return [
					'tournaments' => array_map(function (Tournament $t): string {
						return $this->line($t);
					}, $active),
					// The pretty list with lineups is sent to the chat by the bot itself.
					'show_list' => true,
					'note' => 'Красивый список турниров с составами отправится в чат автоматически. Не пересказывай его: ответь одной короткой фразой (или ничего не добавляй, если нечего).',
				];

			case 'levels_help':
				return [
					'show_levels' => true,
					'note' => 'Справка по уровням отправится в чат автоматически. Не пересказывай её, добавь максимум одну короткую фразу.',
				];

			case 'tournament_info':
				$t = $this->tournament($ctx, $a, TournamentStatus::active());
				return [
					'tournament_id' => $this->no($t),
					'info' => $this->line($t),
					'lineup' => self::plain($this->announcer->lineupText($t)),
				];

			case 'register_team':
				$t = $this->tournament($ctx, $a);
				$this->assertMember($ctx, $t);
				$ids = [];
				foreach ((array) ($a['players'] ?? []) as $ref) {
					$ids[] = $this->player_resolver->fromReference((string) $ref, $actor)->id;
				}
				$entry = $this->teams->createTeam($t->id, $actor->id, (string) ($a['team_name'] ?? ''), $ids);
				return $this->teamResult($t, $entry, 'Команда создана.');

			case 'add_player':
				[$t, $entry] = $this->team($ctx, $a);
				$player = $this->player_resolver->fromReference((string) ($a['player'] ?? ''), $actor);
				return $this->teamResult(
					$t,
					$this->teams->addPlayer($actor->id, $entry->team->id, $player->id),
					'Игрок добавлен.',
				);

			case 'remove_player':
				[$t, $entry] = $this->team($ctx, $a);
				$player = $this->player_resolver->fromReference((string) ($a['player'] ?? ''), $actor);
				return $this->teamResult(
					$t,
					$this->teams->removePlayer($actor->id, $entry->team->id, $player->id),
					'Игрок убран.',
				);

			case 'replace_player':
				[$t, $entry] = $this->team($ctx, $a);
				$out = $this->player_resolver->fromReference((string) ($a['out_player'] ?? ''), $actor);
				$in = $this->player_resolver->fromReference((string) ($a['in_player'] ?? ''), $actor);
				return $this->teamResult(
					$t,
					$this->teams->replacePlayer($actor->id, $entry->team->id, $out->id, $in->id),
					'Замена сделана.',
				);

			case 'rename_team':
				[$t, $entry] = $this->team($ctx, $a);
				return $this->teamResult(
					$t,
					$this->teams->renameTeam($actor->id, $entry->team->id, (string) ($a['new_name'] ?? '')),
					'Команда переименована.',
				);

			case 'transfer_captain':
				[$t, $entry] = $this->team($ctx, $a);
				$player = $this->player_resolver->fromReference((string) ($a['player'] ?? ''), $actor);
				return $this->teamResult(
					$t,
					$this->teams->transferCaptain($actor->id, $entry->team->id, $player->id),
					'Капитан сменился.',
				);

			case 'withdraw_team':
				[$t, $entry] = $this->team($ctx, $a);
				$this->teams->withdrawTeam($actor->id, $entry->team->id);
				return [
					'tournament_id' => $this->no($t),
					'message' => sprintf('Команда «%s» снята с турнира.', $entry->team->name),
					'summary' => $this->tournaments->lineup($t->id)->summary(),
				];

			case 'leave_team':
				$t = $this->tournament($ctx, $a);
				$entry = $this->teams->teamOf($t->id, $actor->id) ?? throw new NotFound('Ты не состоишь в команде на этом турнире.');
				return $this->teamResult(
					$t,
					$this->teams->leaveTeam($actor->id, $entry->team->id),
					'Ты вышел из команды.',
				);

			case 'join_solo':
				$t = $this->tournament($ctx, $a);
				$this->assertMember($ctx, $t);
				$this->teams->joinSolo($t->id, $actor->id);
				return [
					'tournament_id' => $this->no($t),
					'placement' => $this->placementLabel($t, $actor->id),
					'summary' => $this->tournaments->lineup($t->id)->summary(),
				];

			case 'leave_solo':
				$t = $this->tournament($ctx, $a);
				$this->teams->leaveSolo($t->id, $actor->id);
				return ['tournament_id' => $this->no($t), 'message' => 'Соло-заявка снята.'];

			case 'my_status':
				return ['status' => $this->statusLines($ctx)];

			case 'ask_clarification':
				return [
					'note' => 'Задай пользователю этот вопрос своими словами.',
					'question' => (string) ($a['question'] ?? ''),
				];

			case 'create_tournament':
				$starts_at = MoscowTime::parseHuman((string) ($a['starts_at_msk'] ?? ''), $this->clock->now());
				$reference = self::levelArg($a);
				// Always a draft: publishing needs the organizer's explicit confirmation of the card.
				$t = $this->tournaments->create(new CreateTournament(
					$ctx->isPrivate() && $this->groups !== null ? $this->groups->forCreate($actor->id) : $ctx->groupId(),
					$actor->id,
					(string) ($a['title'] ?? ''),
					MoscowTime::toInput($starts_at),
					(int) ($a['team_size'] ?? 0),
					$reference,
					// Only the owner picks a scale (the parameter is offered to him only).
					$ctx->is_owner && isset($a['balance_id']) ? (int) $a['balance_id'] : null,
					isset($a['max_players']) ? (int) $a['max_players'] : null,
				));
				$this->announcer->renumber($t->chat_id, $t->id);
				return $this->cardResult($t, 'Черновик создан.');

			case 'edit_tournament':
				$t = $this->tournament(
					$ctx,
					$a,
					[TournamentStatus::DRAFT, TournamentStatus::REGISTRATION, TournamentStatus::CLOSED],
				);
				$time = isset($a['starts_at_msk']) ? MoscowTime::toInput(MoscowTime::parseHuman(
					(string) $a['starts_at_msk'],
					$this->clock->now(),
				)) : null;
				$t = $this->tournaments->edit($actor->id, $t->id, new EditTournament(
					isset($a['title']) ? (string) $a['title'] : null,
					$time,
					isset($a['team_size']) ? (int) $a['team_size'] : null,
					self::levelArg($a),
					null,
					isset($a['max_players']) ? (int) $a['max_players'] : null,
					false,
				));
				$this->announcer->renumber($t->chat_id, $t->id); // a new start time may change the order
				if ($t->status === TournamentStatus::DRAFT) {
					return $this->cardResult($t, 'Черновик обновлён.');
				}
				return ['tournament_id' => $this->no($t), 'info' => $this->line($t)];

			case 'publish_tournament':
				if ($this->drafts === null) {
					throw new ValidationError('Публикация недоступна.');
				}
				$t = $this->tournament($ctx, $a, [TournamentStatus::DRAFT]);
				if ($t->status === TournamentStatus::DRAFT && ($t->card_msg_id === null || !$this->drafts->canConfirm($t, $actor->id))) {
					// The card was not shown to this person yet: show it and wait for their answer.
					$this->tournaments->assertCanManage($actor->id, $t->id);
					return $this->cardResult(
						$t,
						'Сначала покажу карточку, турнир НЕ опубликован.',
					) + ['published' => false];
				}
				if (!Confirmation::isClearYes($ctx->message->textWithoutMention($this->botUsername()))) {
					// Not a clear "yes": show the card again and ask again.
					return $this->cardResult(
						$t,
						'Пользователь не подтвердил публикацию однозначно, турнир НЕ опубликован.',
					) + ['published' => false];
				}
				$t = $this->drafts->publish($actor->id, $t->id);
				return [
					'tournament_id' => $this->no($t),
					'published' => true,
					'message' => 'Опубликовано: регистрация открыта, анонс в группе «' . $this->groupTitle($t) . '».',
				];

			case 'cancel_tournament':
				$target = $this->tournament(
					$ctx,
					$a,
					[TournamentStatus::DRAFT, TournamentStatus::REGISTRATION, TournamentStatus::CLOSED],
				);
				$number = $this->no($target);
				$t = $this->tournaments->cancel($actor->id, $target->id);
				$this->announcer->refresh($t->id);
				$this->announcer->renumber($t->chat_id, $t->id);
				return [
					'tournament_id' => $number,
					'message' => sprintf('Турнир «%s» отменён. Номера остальных турниров могли сдвинуться.', $t->title),
				];

			case 'open_registration':
				// Drafts are published only through publish_tournament after confirmation.
				$t = $this->tournament($ctx, $a, [TournamentStatus::CLOSED]);
				if ($t->status === TournamentStatus::DRAFT) {
					// Same as /open for a draft: show the card, publish only after a clear yes.
					$this->tournaments->assertCanManage($actor->id, $t->id);
					return $this->cardResult(
						$t,
						'Это черновик: показываю карточку, публикация только после явного «да».',
					) + ['published' => false];
				}
				$t = $this->tournaments->openRegistration($actor->id, $t->id);
				$this->announcer->publish($t->id);
				return [
					'tournament_id' => $this->no($t),
					'message' => 'Регистрация снова открыта, анонс опубликован в группе «' . $this->groupTitle($t) . '».',
				];

			case 'close_registration':
				$t = $this->tournaments->closeRegistration(
					$actor->id,
					$this->tournament($ctx, $a, [TournamentStatus::REGISTRATION])->id,
				);
				return ['tournament_id' => $this->no($t), 'message' => 'Регистрация закрыта.'];

			case 'set_max_players':
				$t = $this->tournaments->setMaxPlayers(
					$actor->id,
					$this->tournament($ctx, $a)->id,
					(int) ($a['max_players'] ?? 0),
				);
				return ['tournament_id' => $this->no($t), 'summary' => $this->tournaments->lineup($t->id)->summary()];

			case 'set_level':
				$player = $this->player_resolver->fromReference((string) ($a['player'] ?? ''), $actor);
				$level = Levels::parse((string) ($a['level'] ?? '')) ?? throw new ValidationError('Уровень должен быть от 1 до 6 или названием уровня.');
				$this->balances->setLevel(
					$ctx->groupId(),
					$actor->id,
					$player->id,
					$level,
					isset($a['balance_id']) ? (int) $a['balance_id'] : null,
				);
				return ['message' => sprintf(
					'%s: %s (%d).',
					$player->mention(),
					Levels::name($level),
					$level,
				)];

			case 'create_balance':
				$balance = $this->balances->create(
					$ctx->groupId(),
					$actor->id,
					(string) ($a['name'] ?? ''),
					isset($a['based_on_balance_id']) ? (int) $a['based_on_balance_id'] : null,
				);
				return [
					'balance_id' => $balance->id,
					'message' => sprintf('Шкала «%s» создана (№%d).', $balance->name, $balance->id),
				];

			case 'show_balance':
				if (isset($a['balance_id'])) {
					$balance = $this->balances->get((int) $a['balance_id']);
				} else {
					$balance = $this->balance_repo->listByOwner($actor->id)[0] ?? $this->balances->findDefault();
					if ($balance === null) {
						throw new NotFound('Шкал баланса пока нет.');
					}
				}
				$ratings = [];
				foreach ($this->balances->effectiveRatings($balance->id) as $player_id => $rating) {
					$p = $this->players->find($player_id);
					$ratings[] = sprintf(
						'%s — %s (%d)',
						$p !== null ? $p->mention() : '#' . $player_id,
						Levels::name($rating['level']),
						$rating['level'],
					);
				}
				return [
					'balance_id' => $balance->id,
					'name' => $balance->name,
					'ratings' => $ratings,
					'note' => 'Остальные игроки — средние (3).',
				];
		}
		throw new ValidationError('Такого действия нет.');
	}

	/**
		@param array<string, mixed> $a
		@param list<string>|null $statuses
	*/
	protected function tournament(Context $ctx, array $a, ?array $statuses = null): Tournament {
		$id = isset($a['tournament_id']) && $a['tournament_id'] !== '' ? (int) $a['tournament_id'] : null;
		return $this->resolver->resolve(
			$ctx->groupId(),
			$id,
			$statuses ?? [TournamentStatus::REGISTRATION, TournamentStatus::CLOSED],
		);
	}

	/**
		Team by name, or the team of the author.

		@param array<string, mixed> $a
		@return array{0: Tournament, 1: TeamEntry}
	*/
	protected function team(Context $ctx, array $a): array {
		$t = $this->tournament($ctx, $a);
		$name = trim((string) ($a['team_name'] ?? ''));
		if ($name !== '') {
			$entry = $this->teams->findByName($t->id, $name);
			if ($entry === null) {
				throw new NotFound(sprintf('Команды «%s» на этом турнире нет.', $name));
			}
			return [$t, $entry];
		}
		$entry = $this->teams->teamOf($t->id, $ctx->actor->id);
		if ($entry === null) {
			throw new NotFound('Ты не состоишь в команде на этом турнире.');
		}
		return [$t, $entry];
	}

	/**
		@return array<string, mixed>
	*/
	protected function teamResult(Tournament $t, TeamEntry $entry, string $message): array {
		$members = [];
		foreach ($entry->members as $member) {
			$p = $this->players->find($member->player_id);
			$members[] = ($p !== null ? $p->mention() : '#' . $member->player_id) . ($member->player_id === $entry->team->captain_id ? ' (капитан)' : '');
		}
		$t = $this->tournaments->get($t->id);
		$placement = null;
		if ($entry->team->isRegistered()) {
			$placement = $this->tournaments->lineup($t->id)->placementOf($entry->team->captain_id) === 'reserve' ? 'резерв' : 'основной состав';
		}
		return [
			'tournament_id' => $this->no($t),
			'message' => $message,
			'team' => $entry->team->name,
			'members' => $members,
			'size' => sprintf('%d из %d', $entry->size(), $t->team_size),
			'registered' => $entry->team->isRegistered(),
			'placement' => $placement ?? 'команда ещё собирается: не хватает игроков',
			'points' => $t->hasLevelLimit() ? sprintf(
				'%d из %d',
				$entry->points(),
				(int) $t->max_points,
			) : 'без лимита',
		];
	}

	/**
		Result for a created/edited draft: the card with «Публикуем или что-то поправить?» is
		shown by PHP right after this turn (show_card), the model must not write it itself.

		@return array<string, mixed>
	*/
	protected function cardResult(Tournament $t, string $message): array {
		return [
			'tournament_id' => $this->no($t),
			'show_card' => $t->id,
			'message' => $message,
			'card' => $this->drafts !== null ? self::plain($this->drafts->cardText($t)) : $this->line($t),
			'note' => 'Карточка с вопросом «Публикуем или что-то поправить?» и кнопками будет показана автоматически. Публикуй только после явного «да».',
		];
	}

	/**
		Tournament level from the model: "level" (old name "reference_team" still accepted).
	*/
	protected static function levelArg(array $a): ?string {
		$value = $a['level'] ?? $a['reference_team'] ?? null;
		if ($value === null || (is_string($value) && trim($value) === '')) {
			return null;
		}
		return is_array($value) ? implode(',', array_map('strval', $value)) : (string) $value;
	}

	/**
		In private anyone can open the bot: joining checks that the user is in the tournament's group.
	*/
	protected function assertMember(Context $ctx, Tournament $t): void {
		if ($ctx->isPrivate() && $this->groups !== null) {
			$this->groups->assertMember($t->chat_id, $ctx->actor->tg_user_id);
		}
	}

	protected function groupTitle(Tournament $t): string {
		return $this->groups !== null ? $this->groups->title($t->chat_id) : 'турнира';
	}

	/**
		Short per-group number the user sees (null once finished/cancelled).
	*/
	protected function no(Tournament $t): ?int {
		return $this->resolver->number($t)->number;
	}

	protected function botUsername(): string {
		return $this->bot_username;
	}

	protected function placementLabel(Tournament $t, int $player_id): string {
		$placement = $this->tournaments->lineup($t->id)->placementOf($player_id);
		return $placement === 'reserve' ? 'резерв' : ($placement === 'forming' ? 'команда собирается' : 'основной состав');
	}

	/**
		Unpublished drafts of the author (for the model's context).

		@return list<string>
	*/
	public function draftLines(Context $ctx): array {
		$lines = [];
		foreach ($this->tournaments->listByStatus($ctx->groupId(), [TournamentStatus::DRAFT]) as $t) {
			if ($t->organizer_id !== $ctx->actor->id && $t->card_shown_to !== $ctx->actor->id) {
				continue;
			}
			$lines[] = '№' . $this->no($t) . ' ' . Messages::tournamentLine($t, $this->tournaments->lineup($t->id))
				. ($t->card_msg_id !== null ? ' — карточка показана, ждём ответа «Публикуем или что-то поправить?»' : ' — карточка ещё не показана');
		}
		return $lines;
	}

	/**
		@return list<string>
	*/
	public function statusLines(Context $ctx): array {
		$lines = [];
		foreach ($this->tournaments->listActive($ctx->groupId()) as $t) {
			$entry = $this->teams->teamOf($t->id, $ctx->actor->id);
			$placement = $this->tournaments->lineup($t->id)->placementOf($ctx->actor->id);
			if ($placement === null) {
				continue;
			}
			$where = $entry !== null
				? sprintf(
					'в команде «%s»%s',
					$entry->team->name,
					$entry->team->captain_id === $ctx->actor->id ? ' (капитан)' : '',
				)
				: 'соло';
			$lines[] = sprintf(
				'№%d «%s»: %s, %s',
				(int) $this->no($t),
				$t->title,
				$where,
				$this->placementLabel($t, $ctx->actor->id),
			);
		}
		return $lines === [] ? ['Нигде не заявлен.'] : $lines;
	}

	protected function line(Tournament $t): string {
		return '№' . $this->no($t) . ' ' . Messages::tournamentLine($t, $this->tournaments->lineup($t->id)) . ', ' . TournamentStatus::label($t->status);
	}

	protected static function plain(string $html): string {
		return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}
}
