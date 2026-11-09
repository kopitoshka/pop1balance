<?php

declare(strict_types=1);

namespace P1Bot\Ai;

use P1Bot\App\Announcer;
use P1Bot\App\CreateAccess;
use P1Bot\App\TeamDisbandNotifier;
use P1Bot\App\TeamAddFlow;
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
use P1Bot\Domain\Exception\PlayerSuggestError;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Levels;
use P1Bot\Domain\NickService;
use P1Bot\Domain\Model\Player;
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
		protected ?CreateAccess $create_access = null,
		protected ?TeamDisbandNotifier $disband_notifier = null,
		protected ?NickService $nicks = null,
		protected ?TeamAddFlow $team_add = null,
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
			if (in_array($tool, Tools::ADMIN_TOOLS, true) && !$this->canOrganize($ctx)) {
				throw new PermissionDenied(CreateAccess::REFUSAL);
			}
			$result = $this->run($tool, $args, $ctx);
			if (in_array($tool, Tools::MUTATING, true) && isset($result['tournament_id'])) {
				// tournament_id in results is the global number (= database id).
				$this->announcer->refresh((int) $result['tournament_id']);
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
		} catch (PlayerSuggestError $e) {
			return [
				'ok' => false,
				'error' => $e->getMessage(),
				'suggest_markup' => $e->replyMarkup(),
				'note' => 'Кнопки «Может, ты про…» уже уйдут в чат. Попроси нажать подходящую; ники не выдумывай.',
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
				$active = $this->listVisible($ctx);
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

			case 'list_history':
				$past = $this->tournaments->listRecentByStatus(TournamentStatus::past(), 10);
				$lines = [];
				foreach ($past as $t) {
					$lineup = $this->tournaments->lineup($t->id);
					$teams = count($lineup->main_teams) + count($lineup->reserve_teams);
					$players = $lineup->occupied() + $lineup->reserveCount();
					$lines[] = sprintf(
						'№%d %s — %s, команды по %d, команд %d / игроков %d, %s',
						$t->id,
						$t->title,
						MoscowTime::format($t->starts_at),
						$t->team_size,
						$teams,
						$players,
						TournamentStatus::label($t->status),
					);
				}
				return [
					'tournaments' => $lines,
					'show_past_list' => true,
					'note' => 'Список прошедших турниров отправится в чат автоматически. Не пересказывай его: максимум одна короткая фраза.',
				];

			case 'levels_help':
				return [
					'show_levels' => true,
					'note' => 'Справка по уровням отправится в чат автоматически. Не пересказывай её, добавь максимум одну короткую фразу.',
				];

			case 'player_level':
				$ref = trim((string) ($a['player'] ?? ''));
				$player = $ref === ''
					? $actor
					: $this->resolvePlayer($ref, $actor, $ctx, 'player_level', $a, 'player');
				$scale_id = $this->balances->viewScaleId();
				$explicit = $this->balances->explicitLevel($scale_id, $player->id);
				$level = $explicit ?? Levels::DEFAULT;
				$own = $player->id === $actor->id;
				if ($explicit === null) {
					$text = $own
						? sprintf('Уровень ещё не ставили — пока считаю %s (%d).', Levels::name($level), $level)
						: sprintf('%s — уровень ещё не ставили, считаю %s (%d).', $player->mention(), Levels::name($level), $level);
				} else {
					$text = $own
						? sprintf('Твой уровень: %s (%d)', Levels::name($level), $level)
						: sprintf('%s — %s (%d)', $player->mention(), Levels::name($level), $level);
				}
				return [
					'player' => $player->mention(),
					'level' => $level,
					'level_name' => Levels::name($level),
					'set' => $explicit !== null,
					'message' => $text . "\n\nВсе уровни — /levels",
					'note' => 'Ответь пользователю этим текстом (можно чуть короче). Шкалы не упоминай.',
				];

			case 'list_player_levels':
				if (!$ctx->isPrivate()) {
					return [
						'ok' => false,
						'error' => 'Список уровней смотри в личке: /level all',
					];
				}
				$scale = $this->balances->findDefault();
				if ($scale === null) {
					return ['players' => [], 'message' => 'Пока никому уровень не ставили 🙂'];
				}
				$rows = [];
				foreach ($this->balances->effectiveRatings($scale->id) as $player_id => $rating) {
					$p = $this->players->find($player_id);
					$label = $p !== null ? $p->mention() : '#' . $player_id;
					$rows[] = [
						'player' => $label,
						'level' => $rating['level'],
						'level_name' => Levels::name($rating['level']),
						'sort' => mb_strtolower($label),
					];
				}
				usort($rows, static function (array $a, array $b): int {
					return $b['level'] <=> $a['level'] ?: ($a['sort'] <=> $b['sort']);
				});
				$lines = [];
				foreach (array_slice($rows, 0, 80) as $row) {
					unset($row['sort']);
					$lines[] = sprintf('%s — %s (%d)', $row['player'], $row['level_name'], $row['level']);
				}
				return [
					'players' => $lines,
					'message' => $lines === []
						? 'Пока никому уровень не ставили 🙂'
						: "Уровни:\n- " . implode("\n- ", $lines) . "\n\nВсе уровни — /levels",
					'note' => 'Перескажи список коротко или почти дословно. Шкалы не упоминай.',
				];


			case 'set_nick':
				if ($this->nicks === null) {
					return ['ok' => false, 'error' => 'Ники недоступны.'];
				}
				if (!$ctx->isPrivate()) {
					return ['ok' => false, 'error' => 'Ник ставится только в личке со мной.'];
				}
				$player = $this->nicks->setOwn($actor->id, (string) ($a['nick'] ?? ''));
				return [
					'nick' => $player->ign,
					'message' => sprintf('Поставила тебе ник «%s».', $player->ign),
				];

			case 'show_nick':
				$ref = trim((string) ($a['player'] ?? ''));
				$player = $ref === '' ? $actor : $this->resolvePlayer($ref, $actor, $ctx, 'show_nick', $a, 'player');
				if (!$player->hasIgn()) {
					$msg = $player->id === $actor->id
						? 'Ник пока не привязан. Поставить: /nick <ник в игре> или скажи мне свой ник.'
						: sprintf('У %s ник пока не привязан.', $player->mention());
				} else {
					$msg = $player->id === $actor->id
						? sprintf('Твой ник: %s', $player->ign)
						: sprintf('%s — ник %s', $player->mention(), $player->ign);
				}
				return ['player' => $player->mention(), 'nick' => $player->ign, 'message' => $msg];

			case 'assign_nick':
				if ($this->nicks === null) {
					return ['ok' => false, 'error' => 'Ники недоступны.'];
				}
				$target = $this->resolvePlayer((string) ($a['player'] ?? ''), $actor, $ctx, 'assign_nick', $a, 'player', true);
				$result = $this->nicks->assignByOwner($actor->id, $target->id, (string) ($a['nick'] ?? ''));
				$msg = sprintf('Поставила ник «%s» игроку %s.', $result['player']->ign, $result['player']->mention());
				if ($result['previous_holder'] !== null) {
					$msg .= sprintf(' Раньше он был у %s — забрала.', $result['previous_holder']->mention());
				}
				return ['message' => $msg, 'nick' => $result['player']->ign];

			case 'tournament_info':
				$t = $this->tournament($ctx, $a, TournamentStatus::infoable());
				return [
					'tournament_id' => $this->no($t),
					'info' => $this->line($t),
					'lineup' => self::plain($this->announcer->lineupText($t)),
				];

			case 'register_team':
				$t = $this->tournament($ctx, $a);
				$ids = [];
				foreach ((array) ($a['players'] ?? []) as $i => $ref) {
					$ids[] = $this->resolvePlayer((string) $ref, $actor, $ctx, 'register_team', $a, 'players.' . $i)->id;
				}
				$entry = $this->teams->createTeam($t->id, $actor->id, (string) ($a['team_name'] ?? ''), $ids);
				return $this->teamResult($t, $entry, 'Команда создана.');

			case 'add_player':
				[$t, $entry] = $this->team($ctx, $a);
				$ref = trim((string) ($a['player'] ?? ''));
				if ($ref === '') {
					return $this->addPlayerOffer($ctx, $actor, $entry->team->id);
				}
				// Partial nick without @: suggest matches instead of hard fail.
				if ($ref[0] !== '@' && !preg_match('/^[A-Za-z][A-Za-z0-9_]{2,31}$/', $ref)) {
					$partial = $this->players->searchPartial($ref, 5, $this->players->playersOnTeamsOfTournament($t->id));
					if ($partial !== [] && $this->players->findKnownByUsername(ltrim($ref, '@')) === null
						&& $this->players->findByIgnNorm(mb_strtolower($ref)) === null) {
						return $this->partialPlayerSuggest($ctx, $actor, $entry->team->id, $ref, $partial, $a);
					}
				}
				$player = $this->resolvePlayer($ref, $actor, $ctx, 'add_player', $a, 'player');
				return $this->teamResult(
					$t,
					$this->teams->addPlayer($actor->id, $entry->team->id, $player->id),
					'Игрок добавлен.',
				);

			case 'remove_player':
				[$t, $entry] = $this->team($ctx, $a);
				$player = $this->resolvePlayer((string) ($a['player'] ?? ''), $actor, $ctx, 'remove_player', $a, 'player');
				return $this->teamResult(
					$t,
					$this->teams->removePlayer($actor->id, $entry->team->id, $player->id),
					'Игрок убран.',
				);

			case 'replace_player':
				[$t, $entry] = $this->team($ctx, $a);
				$out = $this->resolvePlayer((string) ($a['out_player'] ?? ''), $actor, $ctx, 'replace_player', $a, 'out_player');
				$in = $this->resolvePlayer((string) ($a['in_player'] ?? ''), $actor, $ctx, 'replace_player', $a, 'in_player');
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
				$player = $this->resolvePlayer((string) ($a['player'] ?? ''), $actor, $ctx, 'transfer_captain', $a, 'player');
				return $this->teamResult(
					$t,
					$this->teams->transferCaptain($actor->id, $entry->team->id, $player->id),
					'Капитан сменился.',
				);

			case 'withdraw_team':
				[$t, $entry] = $this->team($ctx, $a);
				$disband = $this->teams->withdrawTeam($actor->id, $entry->team->id);
				if ($this->disband_notifier !== null) {
					$this->disband_notifier->notify($actor->id, $disband);
				}
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
					$this->groups !== null ? $this->groups->originForCreate($actor->id) : ($ctx->isPrivate() ? null : $ctx->groupId()),
					$actor->id,
					(string) ($a['title'] ?? ''),
					MoscowTime::toInput($starts_at),
					(int) ($a['team_size'] ?? 0),
					$reference,
					// Only the owner picks a scale (the parameter is offered to him only).
					null, // single global scale
					isset($a['max_players']) ? (int) $a['max_players'] : null,
				));
				$this->announcer->renumber($t->id);
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
				$this->announcer->renumber($t->id); // a new start time may change the order
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
					'message' => 'Опубликовано: регистрация открыта, анонс отправлен во все активные группы.',
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
				$this->announcer->renumber($t->id);
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
					'message' => 'Регистрация снова открыта, анонс опубликован во всех активных группах.',
				];


			case 'set_max_players':
				$t = $this->tournaments->setMaxPlayers(
					$actor->id,
					$this->tournament($ctx, $a)->id,
					(int) ($a['max_players'] ?? 0),
				);
				return ['tournament_id' => $this->no($t), 'summary' => $this->tournaments->lineup($t->id)->summary()];

			case 'set_level':
				$player = $this->resolvePlayer((string) ($a['player'] ?? ''), $actor, $ctx, 'set_level', $a, 'player', true);
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


		}
		throw new ValidationError('Такого действия нет.');
	}

	/**
		@param array<string, mixed> $a
		@param list<string>|null $statuses
	*/

	/**
		@param array<string, mixed> $tool_args full tool args for resume
	*/
	protected function resolvePlayer(string $ref, Player $actor, Context $ctx, string $tool, array $tool_args, string $player_arg, bool $owner_only = false): Player {
		$resume = [
			'kind' => 'tool',
			'tool' => $tool,
			'args' => $tool_args,
			'player_arg' => $player_arg,
			'owner_only' => $owner_only,
		];
		return $this->player_resolver->fromReference($ref, $actor, $resume, $ctx->chatId());
	}

	protected function tournament(Context $ctx, array $a, ?array $statuses = null): Tournament {
		$id = isset($a['tournament_id']) && $a['tournament_id'] !== '' ? (int) $a['tournament_id'] : null;
		return $this->resolver->resolve(
			$ctx->isPrivate() ? null : $ctx->groupId(),
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
				$this->teams->pointsOf($entry),
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

	protected function groupTitle(Tournament $t): string {
		if ($this->groups === null || $t->chat_id === null) {
			return 'всех групп';
		}
		return $this->groups->title($t->chat_id);
	}

	protected function canOrganize(Context $ctx): bool {
		if ($this->create_access !== null) {
			return $this->create_access->canCreate($ctx->actor->id);
		}
		return $ctx->is_admin || $ctx->is_owner;
	}

	/**
		Global tournament number (= id) the user sees (including finished/cancelled).
	*/
	protected function no(Tournament $t): ?int {
		return $this->resolver->number($t)->number;
	}

	protected function botUsername(): string {
		return $this->bot_username;
	}


	/**
		Active tournaments visible in this context: one group in a group chat, every group in private.

		@return list<Tournament>
	*/
	protected function listVisible(Context $ctx): array {
		unset($ctx);
		return $this->tournaments->listAllActive();
	}

	/**
		@return list<Tournament>
	*/
	protected function draftCandidates(Context $ctx): array {
		unset($ctx);
		return $this->tournaments->listAllByStatus([TournamentStatus::DRAFT]);
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
		foreach ($this->draftCandidates($ctx) as $t) {
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
		foreach ($this->listVisible($ctx) as $t) {
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

	/**
		«добавь в мою команду» without a nick — candidate buttons + pending text search.
		@return array<string, mixed>
	*/
	protected function addPlayerOffer(Context $ctx, \P1Bot\Domain\Model\Player $actor, int $team_id): array {
		if ($this->team_add === null || !$ctx->isPrivate()) {
			return [
				'ok' => false,
				'error' => 'Открой карточку команды (/team) и нажми «➕ Добавить игрока», или укажи @username.',
			];
		}
		$markup = $this->team_add->suggestMarkup($actor, $team_id, $ctx->chatId());
		// also send the telegram picker via offer? suggestMarkup only inline. Call offer for full UX.
		$this->team_add->offer($ctx->chatId(), $actor, $team_id, $ctx->message->message_id);
		return [
			'message' => 'Кого добавить? Выбери кнопкой, из Telegram или напиши часть ника.',
			'suggest_markup' => [],
			'note' => 'Кнопки уже отправлены в чат. Не дублируй список ников.',
		];
	}

	/**
		Partial nick match → pick buttons that add to the team (reuse sug: pending with resume add_player).
		@param list<\P1Bot\Domain\Model\Player> $partial
		@param array<string, mixed> $args
		@return array<string, mixed>
	*/
	protected function partialPlayerSuggest(
		Context $ctx,
		\P1Bot\Domain\Model\Player $actor,
		int $team_id,
		string $ref,
		array $partial,
		array $args,
	): array {
		// Store resume as add_player with team fixed via args; sug callback re-runs tool.
		$resume = [
			'kind' => 'tool',
			'tool' => 'add_player',
			'args' => array_merge($args, ['_team_id' => $team_id]),
			'player_field' => 'player',
		];
		// Use PlayerSuggestError path via pending in player_resolver — simpler: tadd buttons directly.
		$rows = [];
		foreach ($partial as $player) {
			$rows[] = [[
				'text' => mb_substr($player->hasIgn() ? ((string) $player->ign) : $player->mention(), 0, 64),
				'callback_data' => 'tadd:' . $team_id . ':' . $player->id,
			]];
		}
		return [
			'message' => 'Нашла похожих по «' . $ref . '» — выбери:',
			'suggest_markup' => $rows === [] ? [] : ['reply_markup' => ['inline_keyboard' => $rows]],
		];
	}


}
