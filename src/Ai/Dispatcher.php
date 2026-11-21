<?php

declare(strict_types=1);

namespace P1Bot\Ai;

use P1Bot\App\Announcer;
use P1Bot\App\PlayerResolver;
use P1Bot\App\TournamentResolver;
use P1Bot\Domain\BalanceService;
use P1Bot\Domain\Exception\DomainError;
use P1Bot\Domain\Exception\PlayerSuggestError;
use P1Bot\Domain\Levels;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\MoscowTime;
use P1Bot\Domain\TeamService;
use P1Bot\Domain\TournamentService;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Handlers\Context;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Logger;
use P1Bot\View\Messages;
use Throwable;

/**
	Executes model function calls through the domain services.
	The model only shows information; it never decides permissions or changes data.
	Results are plain arrays sent back to the model as JSON.
*/
class Dispatcher {

	public function __construct(
		protected TournamentService $tournaments,
		protected TeamService $teams,
		protected BalanceService $balances,
		protected PlayerRepository $players,
		protected TournamentResolver $resolver,
		protected PlayerResolver $player_resolver,
		protected Announcer $announcer,
		protected Logger $logger,
	) {
	}

	/**
		@param array<string, mixed> $args
		@return array<string, mixed>
	*/
	public function dispatch(string $tool, array $args, Context $ctx): array {
		try {
			if (!in_array($tool, Tools::names(), true)) {
				return [
					'ok' => false,
					'error' => 'Это действие больше недоступно.',
					'what_to_do' => 'Объясни, что записаться, собрать команду, поставить ник или создать турнир '
						. 'можно командами и кнопками: /tournaments, /create_tournament, /nick, /level, /players, /help.',
				];
			}
			return ['ok' => true] + $this->run($tool, $args, $ctx);
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
			case 'help':
				return [
					'show_help' => true,
					'note' => 'Справка по командам отправится в чат автоматически. Не пересказывай её, добавь максимум одну короткую фразу.',
				];

			case 'list_tournaments':
				$active = $this->listVisible();
				if ($active === []) {
					return ['tournaments' => []];
				}
				return [
					'tournaments' => array_map(function (Tournament $t): string {
						return $this->line($t);
					}, $active),
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
					'message' => $text . "\n\nКак устроены уровни — /help_level",
					'note' => 'Ответь пользователю этим текстом (можно чуть короче). Шкалы не упоминай.',
				];

			case 'list_player_levels':
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
						: "Уровни:\n- " . implode("\n- ", $lines) . "\n\nВсе игроки — /players",
					'note' => 'Перескажи список коротко или почти дословно. Шкалы не упоминай.',
				];

			case 'list_players':
				return [
					'show_players' => true,
					'note' => 'Список игроков отправится в чат автоматически, как /players. Не пересказывай его: максимум одна короткая фраза.',
				];

			case 'show_nick':
				$ref = trim((string) ($a['player'] ?? ''));
				$player = $ref === '' ? $actor : $this->resolvePlayer($ref, $actor, $ctx, 'show_nick', $a, 'player');
				if (!$player->hasIgn()) {
					$msg = $player->id === $actor->id
						? 'Ник пока не привязан. Поставить: /nick игровой_ник'
						: sprintf('У %s ник пока не привязан.', $player->mention());
				} else {
					$msg = $player->id === $actor->id
						? sprintf('Твой ник: %s. Сменить: /nick игровой_ник', $player->ign)
						: sprintf('%s — ник %s', $player->mention(), $player->ign);
				}
				return ['player' => $player->mention(), 'nick' => $player->ign, 'message' => $msg];

			case 'tournament_info':
				$t = $this->tournament($ctx, $a, TournamentStatus::infoable());
				return [
					'tournament_id' => $this->no($t),
					'info' => $this->line($t),
					'lineup' => self::plain($this->announcer->lineupText($t)),
				];

			case 'my_status':
				return ['status' => $this->statusLines($ctx)];

			case 'ask_clarification':
				return [
					'note' => 'Задай пользователю этот вопрос своими словами.',
					'question' => (string) ($a['question'] ?? ''),
				];
		}
		return [
			'ok' => false,
			'error' => 'Это действие больше недоступно.',
		];
	}

	/**
		@param array<string, mixed> $a
		@param array<string, mixed> $tool_args
	*/
	protected function resolvePlayer(string $ref, Player $actor, Context $ctx, string $tool, array $tool_args, string $player_arg): Player {
		$resume = [
			'kind' => 'tool',
			'tool' => $tool,
			'args' => $tool_args,
			'player_arg' => $player_arg,
			'owner_only' => false,
		];
		return $this->player_resolver->fromReference($ref, $actor, $resume, $ctx->chatId());
	}

	/**
		@param array<string, mixed> $a
		@param list<string>|null $statuses
	*/
	protected function tournament(Context $ctx, array $a, ?array $statuses = null): Tournament {
		$id = isset($a['tournament_id']) && $a['tournament_id'] !== '' ? (int) $a['tournament_id'] : null;
		return $this->resolver->resolve(
			$ctx->isPrivate() ? null : $ctx->groupId(),
			$id,
			$statuses ?? TournamentStatus::infoable(),
		);
	}

	/**
		Global tournament number (= id) the user sees (including finished/cancelled).
	*/
	protected function no(Tournament $t): ?int {
		return $this->resolver->number($t)->number;
	}

	/**
		@return list<Tournament>
	*/
	protected function listVisible(): array {
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
		return $placement === 'reserve' ? 'запас' : ($placement === 'forming' ? 'команда собирается' : 'основной состав');
	}

	/**
		Unpublished drafts of the author (for the model's context), so it can point to the card buttons.

		@return list<string>
	*/
	public function draftLines(Context $ctx): array {
		$lines = [];
		foreach ($this->draftCandidates($ctx) as $t) {
			if ($t->organizer_id !== $ctx->actor->id && $t->card_shown_to !== $ctx->actor->id) {
				continue;
			}
			$lines[] = '№' . $this->no($t) . ' ' . Messages::tournamentLine($t, $this->tournaments->lineup($t->id))
				. ($t->card_msg_id !== null
					? ' — карточка уже есть: править и публиковать можно только кнопками «✏️ Поправить» и «✅ Публикуем»'
					: ' — черновик есть, карточка ещё не показана; открыть: /create_tournament или список турниров');
		}
		return $lines;
	}

	/**
		@return list<string>
	*/
	public function statusLines(Context $ctx): array {
		$lines = [];
		foreach ($this->listVisible() as $t) {
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
