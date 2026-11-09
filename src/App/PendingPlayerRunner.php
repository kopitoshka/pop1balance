<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Ai\Dispatcher;
use P1Bot\Domain\AccessPolicy;
use P1Bot\Domain\BalanceService;
use P1Bot\Domain\Exception\DomainError;
use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Levels;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\NickService;
use P1Bot\Domain\TeamService;
use P1Bot\Handlers\Context;
use P1Bot\Repository\PendingActionRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Clock;
use P1Bot\Telegram\Message;
use P1Bot\Telegram\TelegramApi;
use P1Bot\View\Html;

/**
	Re-runs a stored action after the user picks a fuzzy-suggested player.
*/
class PendingPlayerRunner {

	public function __construct(
		protected TelegramApi $api,
		protected PendingActionRepository $pending,
		protected PlayerRepository $players,
		protected BalanceService $balances,
		protected AccessPolicy $access,
		protected Clock $clock,
		protected ?NickService $nicks = null,
		protected ?TeamService $teams = null,
		protected ?Dispatcher $dispatcher = null,
		protected ?Announcer $announcer = null,
	) {
	}

	public function handle(
		int $pending_id,
		int $chosen_player_id,
		Player $actor,
		int $chat_id,
		string $callback_id,
		?int $message_id,
	): void {
		$row = $this->pending->take($pending_id, $this->clock->now());
		if ($row === null) {
			$this->api->answerCallbackQuery($callback_id, 'Подсказка устарела — напиши ещё раз.', true);
			return;
		}
		if ($row['actor_player_id'] !== $actor->id || $row['chat_id'] !== $chat_id) {
			$this->api->answerCallbackQuery($callback_id, 'Это не твоя подсказка.', true);
			return;
		}
		$action = $row['action'];
		if (!empty($action['owner_only']) && !$this->access->isOwner($actor->id)) {
			$this->api->answerCallbackQuery($callback_id, AccessPolicy::OWNER_ONLY, true);
			return;
		}
		$chosen = $this->players->find($chosen_player_id);
		if ($chosen === null || $chosen->tg_user_id === null) {
			$this->api->answerCallbackQuery($callback_id, 'Игрок уже недоступен.', true);
			return;
		}
		try {
			$text = $this->run($action, $actor, $chosen, $chat_id);
		} catch (DomainError $e) {
			$this->api->answerCallbackQuery($callback_id, $e->getMessage(), true);
			return;
		}
		$this->api->answerCallbackQuery($callback_id, 'Ок!');
		if ($text !== '') {
			$this->api->sendMessage($chat_id, $text, $message_id);
		}
	}

	/**
		@param array<string, mixed> $action
	*/
	protected function run(array $action, Player $actor, Player $chosen, int $chat_id): string {
		$kind = (string) ($action['kind'] ?? '');
		switch ($kind) {
			case 'level_show':
				$scale_id = $this->balances->viewScaleId();
				$explicit = $this->balances->explicitLevel($scale_id, $chosen->id);
				$own = $chosen->id === $actor->id;
				if ($explicit === null) {
					return $own
						? sprintf('Уровень ещё не ставили — пока считаю %s (%d).', Levels::name(Levels::DEFAULT), Levels::DEFAULT)
						: sprintf(
							'%s — уровень ещё не ставили, считаю %s (%d).',
							Html::e($chosen->plainLabel()),
							Levels::name(Levels::DEFAULT),
							Levels::DEFAULT,
						);
				}
				return $own
					? sprintf('Твой уровень: %s (%d)', Levels::name($explicit), $explicit)
					: sprintf('%s — %s (%d)', Html::e($chosen->plainLabel()), Levels::name($explicit), $explicit);

			case 'level_set':
				if (!$this->access->isOwner($actor->id)) {
					throw new PermissionDenied(AccessPolicy::LEVELS_OWNER_ONLY);
				}
				$level = (int) ($action['level'] ?? 0);
				$balance_id = isset($action['balance_id']) ? (int) $action['balance_id'] : null;
				$group_id = isset($action['group_id']) ? (int) $action['group_id'] : $chat_id;
				$this->balances->setLevel($group_id, $actor->id, $chosen->id, $level, $balance_id);
				return sprintf(
					'Записала: %s — %s (%d).',
					Html::e($chosen->mention()),
					Levels::name($level),
					$level,
				);

			case 'nick_show':
				if (!$chosen->hasIgn()) {
					return $chosen->id === $actor->id
						? 'Ник пока не привязан. Привязывает организатор 🙂'
						: sprintf('У %s ник пока не привязан.', Html::e($chosen->mention()));
				}
				return $chosen->id === $actor->id
					? sprintf('Твой ник: <b>%s</b>', Html::e((string) $chosen->ign))
					: sprintf('%s — ник <b>%s</b>.', Html::e($chosen->mention()), Html::e((string) $chosen->ign));

			case 'nick_set':
				if ($this->nicks === null) {
					throw new ValidationError('Ники пока недоступны.');
				}
				if (!$this->access->isOwner($actor->id)) {
					throw new PermissionDenied(AccessPolicy::OWNER_ONLY);
				}
				$result = $this->nicks->assignByOwner($actor->id, $chosen->id, (string) ($action['ign'] ?? ''));
				$msg = sprintf(
					'Поставила ник «%s» игроку %s.',
					Html::e($result['player']->ign ?? (string) ($action['ign'] ?? '')),
					Html::e($result['player']->mention()),
				);
				if ($result['previous_holder'] !== null) {
					$msg .= sprintf(' Раньше он был у %s — забрала.', Html::e($result['previous_holder']->mention()));
				}
				return $msg;

			case 'tool':
				if ($this->dispatcher === null) {
					throw new ValidationError('Действие больше недоступно.');
				}
				$tool = (string) ($action['tool'] ?? '');
				$args = is_array($action['args'] ?? null) ? $action['args'] : [];
				$slot = (string) ($action['player_arg'] ?? 'player');
				$insert = $chosen->username !== null ? '@' . $chosen->username : (string) $chosen->tg_user_id;
				if (str_starts_with($slot, 'players.')) {
					$idx = (int) substr($slot, strlen('players.'));
					$players = is_array($args['players'] ?? null) ? array_values($args['players']) : [];
					if (isset($players[$idx])) {
						$players[$idx] = $insert;
						$args['players'] = $players;
					}
				} elseif ($slot !== '' && array_key_exists($slot, $args)) {
					$args[$slot] = $insert;
				}
				// Synthetic private context for the tool.
				$msg = new Message(
					0,
					$chat_id,
					'private',
					'',
					null,
					'',
					[],
					null,
					null,
				);
				$ctx = new Context(
					$msg,
					$actor,
					$this->access->isOwner($actor->id),
					$this->access->isOwner($actor->id),
					null,
					'',
				);
				$result = $this->dispatcher->dispatch($tool, $args, $ctx);
				if (empty($result['ok'])) {
					throw new ValidationError((string) ($result['error'] ?? 'Не получилось.'));
				}
				if ($this->announcer !== null && isset($result['tournament_id'])) {
					$this->announcer->refresh((int) $result['tournament_id']);
				}
				return (string) ($result['message'] ?? 'Готово!');
		}
		throw new ValidationError('Неизвестное отложенное действие.');
	}
}
