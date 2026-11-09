<?php

declare(strict_types=1);

namespace P1Bot\Handlers;

use P1Bot\App\Announcer;
use P1Bot\App\CreateAccess;
use P1Bot\App\DraftCards;
use P1Bot\App\NeedGroupChoice;
use P1Bot\App\PrivateGroups;
use P1Bot\App\PlayerResolver;
use P1Bot\App\TournamentListing;
use P1Bot\App\TournamentResolver;
use P1Bot\Domain\AccessPolicy;
use P1Bot\Domain\BalanceService;
use P1Bot\Domain\CreateTournament;
use P1Bot\Domain\EditTournament;
use P1Bot\Domain\Exception\DomainError;
use P1Bot\Domain\Exception\InvalidLevel;
use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Levels;
use P1Bot\Domain\NickService;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\MoscowTime;
use P1Bot\Domain\TournamentService;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Repository\BalanceRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Clock;
use P1Bot\Telegram\BotIdentity;
use P1Bot\Telegram\TelegramApi;
use P1Bot\View\DeepLink;
use P1Bot\View\Html;
use P1Bot\View\Messages;
use P1Bot\View\Texts;

/**
	Slash commands: the fallback that works without AI.
*/
class AdminCommands {
	protected const PUBLIC = ['help', 'start', 'tournaments', 'history', 'info', 'level', 'levels', 'nick'];

	/**
		«уровень», «уровень турнира», «максимальный уровень», «макс. уровень» — one field (old «эталон» still works).
	*/
	protected const LEVEL_FIELD = '/^(?:(?:максимальный|макс\.?)\s*уровень(?:\s+турнира)?|уровень(?:\s+турнира)?|эталон|reference|level)(?=\s|$)\s*(.*)$/us';
	protected const CREATE = ['new'];
	protected const MANAGE = ['edit', 'cancel', 'open', 'close'];
	protected const ADMIN = ['new', 'edit', 'cancel', 'open', 'close', 'balance'];

	/**
		Owner only for now (scales are hidden from users, see AccessPolicy::LEVELS_OWNER_ONLY).
	*/
	protected const OWNER = ['balance', 'adduser'];

	protected const LEVEL_ALL_LIMIT = 80;

	/**
		Tournaments an admin command may target when no id is given.
	*/
	protected const MANAGEABLE = [
		TournamentStatus::DRAFT,
		TournamentStatus::REGISTRATION,
		TournamentStatus::CLOSED,
		TournamentStatus::RUNNING,
	];

	public function __construct(
		protected TelegramApi $api,
		protected BotIdentity $bot,
		protected TournamentService $tournaments,
		protected BalanceService $balances,
		protected BalanceRepository $balance_repo,
		protected PlayerRepository $players,
		protected TournamentResolver $resolver,
		protected PlayerResolver $player_resolver,
		protected Announcer $announcer,
		protected Clock $clock,
		protected DraftCards $drafts,
		protected TournamentListing $listing,
		protected ?PrivateGroups $groups = null,
		protected ?CreateAccess $create_access = null,
		protected ?NickService $nicks = null,
	) {
	}

	/**
		Extra sendMessage options for the reply of the current command (e.g. «open» buttons).

		@var array<string, mixed>
	*/
	protected array $reply_extra = [];

	public function supports(string $command): bool {
		return in_array($command, self::PUBLIC, true)
			|| in_array($command, self::ADMIN, true)
			|| in_array($command, self::OWNER, true);
	}

	public function handle(Context $ctx, string $command, string $args): void {
		try {
			if (in_array($command, self::OWNER, true) && !$ctx->is_owner) {
				throw new PermissionDenied(AccessPolicy::OWNER_ONLY);
			}
			// /new: CreateAccess (shared group with owner, or ANYONE_CAN_CREATE). Manage: author|owner only.
			if (in_array($command, self::CREATE, true)) {
				if ($this->create_access !== null) {
					$this->create_access->assertCanCreate($ctx->actor->id);
				} elseif (!$ctx->is_admin && !$ctx->is_owner) {
					throw new PermissionDenied(CreateAccess::REFUSAL);
				}
			}
			$reply = $this->run($ctx, $command, $args);
		} catch (NeedGroupChoice $e) {
			$this->api->sendMessage(
				$ctx->chatId(),
				Html::e($e->getMessage()),
				$ctx->message->message_id,
				PrivateGroups::buttons($e->groups),
			);
			return;
		} catch (DomainError $e) {
			$reply = Html::e($e->getMessage());
		}
		if ($reply !== '') {
			$this->api->sendMessage($ctx->chatId(), $reply, $ctx->message->message_id, $this->reply_extra);
		}
		$this->reply_extra = [];
	}

	/**
		@return string HTML reply ('' = nothing to say)
	*/
	protected function run(Context $ctx, string $command, string $args): string {
		switch ($command) {
			case 'help':
			case 'start':
				if ($ctx->isPrivate()) {
					return Texts::help($this->bot->username, $this->canCreate($ctx), $ctx->is_owner);
				}
				$this->reply_extra = DeepLink::button(
					Messages::GROUP_REDIRECT_BUTTON,
					DeepLink::url($this->bot->username),
				);
				return Texts::groupHelp($this->bot->username);
			case 'tournaments':
				return $this->listTournaments($ctx);
			case 'history':
				return $this->listHistory($ctx);
			case 'adduser':
				return $this->addUser($ctx);
			case 'levels':
				// Management examples only for the owner in private; groups always get the basic text.
				return Texts::levels($ctx->isPrivate() && $ctx->is_owner);
			case 'info':
				[$id] = self::splitId($args);
				$t = $this->resolver->resolve($this->resolveScope($ctx), $id, TournamentStatus::infoable());
				return $this->sendAllButLast($ctx, $this->announcer->infoMessages($t));
			case 'new':
				return $this->create($ctx, $args);
			case 'edit':
				return $this->edit($ctx, $args);
			case 'open':
				return $this->open($ctx, $args);
			case 'close':
				$t = $this->tournaments->closeRegistration($ctx->actor->id, $this->target($ctx, $args)->id);
				$this->announcer->refresh($t->id);
				return sprintf(
					'Регистрация на %s закрыта. Состав зафиксирован, но снять команду ещё можно.',
					Html::b($t->title),
				);
			case 'cancel':
				$t = $this->tournaments->cancel($ctx->actor->id, $this->target($ctx, $args)->id);
				$this->announcer->refresh($t->id);
				$this->announcer->renumber($t->id);
				return sprintf('Турнир %s отменён. Жаль!', Html::b($t->title));
			case 'level':
				return $this->levelCommand($ctx, $args);
			case 'nick':
				return $this->nickCommand($ctx, $args);
			case 'balance':
				return $this->showBalance($ctx, $args);
		}
		return '';
	}

	protected function listTournaments(Context $ctx): string {
		if ($ctx->isPrivate() && $this->groups !== null) {
			$groups = $this->groups->all();
			$messages = $this->listing->privateMessages($groups, $ctx->actor->id, $ctx->is_owner);
			if ($messages === []) {
				return 'Сейчас активных турниров нет.' . ($this->canCreate($ctx) ? ' Создать: /new 23.11 20:00 | Название | 3 | средний, средний, средний+ | 24 или просто напиши, какой турнир нужен.' : '');
			}
			$this->reply_extra = TournamentListing::openButtons(
				$this->listing->privateTournaments($groups, $ctx->actor->id, $ctx->is_owner),
				$groups,
			);
			return $this->sendAllButLast($ctx, $messages);
		}
		// The group list never shows drafts: drafts live in the organizer's private chat.
		$messages = $this->listing->messages($ctx->groupId(), false);
		if ($messages === []) {
			return 'Сейчас активных турниров нет.' . ($this->canCreate($ctx) ? ' Создать: /new 23.11 20:00 | Название | 3 | средний, средний, средний+ | 24' : '');
		}
		return $this->sendAllButLast($ctx, $messages);
	}

	protected function listHistory(Context $ctx): string {
		$messages = $this->listing->historyMessages();
		if ($messages === []) {
			return 'Пока прошедших турниров нет 🙂';
		}
		return $this->sendAllButLast($ctx, $messages);
	}

	/**
		Sends all messages but the last one (as replies); the last one is returned for handle().

		@param list<string> $messages
	*/
	protected function sendAllButLast(Context $ctx, array $messages): string {
		$last = (string) array_pop($messages);
		foreach ($messages as $message) {
			$this->api->sendMessage($ctx->chatId(), $message, $ctx->message->message_id);
		}
		return $last;
	}

	protected function line(Tournament $t): string {
		$this->resolver->number($t);
		return '№' . $t->number . ' ' . Html::e(Messages::tournamentLine($t, $this->tournaments->lineup($t->id)));
	}

	/**
		/new 23.11 20:00 | Название | 3 | средний, средний, средний+ | 24 => draft card with confirmation
	*/
	protected function create(Context $ctx, string $args): string {
		$parts = array_map('trim', explode('|', $args));
		if (count($parts) < 4 || $parts[0] === '' || $parts[1] === '' || $parts[3] === '') {
			throw new ValidationError(
				"Создать турнир можно одной строкой:\n/new 23.11 20:00 | Название | 3 | 10 | 24\n"
				. 'Дата и время по МСК | название | игроков в команде (2–4) | уровень турнира | лимит участников (по умолчанию 24).'
				. "\n\n" . Texts::levelUsage('new')
				. "\n\nИли просто напиши мне, какой турнир нужен, — я уточню детали.",
			);
		}
		$starts_at = MoscowTime::parseHuman($parts[0], $this->clock->now());
		$team_size = self::intArg($parts[2], 'размер команды');
		$reference = $parts[3];
		$max_players = isset($parts[4]) && $parts[4] !== '' ? self::intArg($parts[4], 'лимит участников') : null;

		$chat_id = $this->groups !== null
			? $this->groups->originForCreate($ctx->actor->id)
			: ($ctx->isPrivate() ? null : $ctx->groupId());
		try {
			$t = $this->tournaments->create(new CreateTournament(
				$chat_id,
				$ctx->actor->id,
				$parts[1],
				MoscowTime::toInput($starts_at),
				$team_size,
				$reference,
				null,
				$max_players,
			));
		} catch (InvalidLevel $e) {
			throw new ValidationError($e->getMessage() . "\n\n" . Texts::levelUsage('new', null, $team_size));
		}

		$this->announcer->renumber($t->id);
		$this->drafts->show($t->id, $ctx->chatId(), $ctx->message->message_id, $ctx->actor->id, [
			['role' => 'user', 'content' => '/new ' . $args],
		]);
		return '';
	}

	/**
		/edit [5] поле значение
	*/
	protected function edit(Context $ctx, string $args): string {
		[$id, $rest] = self::splitId($args);
		if (!preg_match('/^(\S+)\s*(.*)$/us', $rest, $m)) {
			throw new ValidationError("Что поменять? Например:\n/edit 1 время 23.11 21:00\n/edit 1 название Кубок выходного дня\n/edit 1 лимит 32\n/edit 1 размер 2 уровень 7\n/edit 1 уровень 10\n/edit 1 уровень средний, средний, высокий");
		}
		$field = mb_strtolower($m[1]);
		$value = trim($m[2]);
		if (preg_match(self::LEVEL_FIELD, mb_strtolower($rest), $lm)) {
			$field = 'уровень';
			$value = trim(mb_substr($rest, mb_strlen($rest) - mb_strlen($lm[1])));
		}
		$t = $this->resolver->resolve($this->resolveScope($ctx), $id, self::MANAGEABLE);

		switch ($field) {
			case 'название':
			case 'title':
				$edit = new EditTournament($value);
				break;
			case 'время':
			case 'дата':
			case 'time':
				$edit = new EditTournament(
					null,
					MoscowTime::toInput(MoscowTime::parseHuman($value, $this->clock->now())),
				);
				break;
			case 'размер':
			case 'size':
				// "размер 2 уровень 7": a new size needs a level for it
				$size_level = null;
				if (preg_match('/^(\S+)\s+(.+)$/us', $value, $sm) && preg_match(
					self::LEVEL_FIELD,
					mb_strtolower($sm[2]),
					$lm,
				)) {
					$value = $sm[1];
					$size_level = trim(mb_substr($sm[2], mb_strlen($sm[2]) - mb_strlen($lm[1])));
				}
				$edit = new EditTournament(null, null, self::intArg($value, 'размер команды'), $size_level);
				break;
			case 'уровень':
				if ($value === '') {
					throw new ValidationError("Не указан уровень турнира.\n\n" . Texts::levelUsage('edit', $t->number, $t->team_size));
				}
				$edit = new EditTournament(null, null, null, $value);
				break;
			case 'лимит':
			case 'limit':
				$edit = new EditTournament(null, null, null, null, null, self::intArg($value, 'лимит участников'));
				break;
			case 'баланс':
			case 'balance':
				if (!$ctx->is_owner) {
					throw new PermissionDenied(AccessPolicy::OWNER_ONLY);
				}
				$balance_id = TournamentResolver::parseId($value) ?? throw new ValidationError('Укажи номер шкалы баланса, например: /edit 5 баланс 2');
				$edit = new EditTournament(null, null, null, null, $balance_id);
				break;
			default:
				throw new ValidationError(sprintf(
					'Не знаю поле «%s». Можно: название, время, размер, уровень, лимит.',
					$field,
				));
		}

		try {
			$t = $this->tournaments->edit($ctx->actor->id, $t->id, $edit);
		} catch (InvalidLevel $e) {
			throw new ValidationError($e->getMessage() . "\n\n" . Texts::levelUsage(
				'edit',
				$t->number,
				$edit->team_size ?? $t->team_size,
			));
		}
		$this->announcer->renumber($t->id); // a new start time may change the order
		if ($t->status === TournamentStatus::DRAFT) {
			$this->drafts->show($t->id, $ctx->chatId(), $ctx->message->message_id, $ctx->actor->id, [
				['role' => 'user', 'content' => '/edit ' . $args],
			]);
			return '';
		}
		$this->announcer->refresh($t->id);
		return "Обновила:\n" . $this->line($t);
	}

	protected function open(Context $ctx, string $args): string {
		$target = $this->target($ctx, $args, [TournamentStatus::DRAFT, TournamentStatus::CLOSED]);
		if ($target->status === TournamentStatus::DRAFT) {
			// Same confirmation as creation: card «Публикуем или что-то поправить?», publish only after a clear yes.
			$this->tournaments->assertCanManage($ctx->actor->id, $target->id);
			$this->drafts->show($target->id, $ctx->chatId(), $ctx->message->message_id, $ctx->actor->id, [
				['role' => 'user', 'content' => '/open ' . $args],
			]);
			return '';
		}
		$t = $this->tournaments->openRegistration($ctx->actor->id, $target->id);
		// A reopened tournament gets a fresh announcement; buttons on the old one keep working.
		$this->announcer->publish($t->id);
		return '';
	}

	/**
		/level | /level @user | /level all (private) | /level @user 4 (owner).
		Reads the same global view scale that team point limits use (default scale).
	*/
	protected function levelCommand(Context $ctx, string $args): string {
		$args = trim($args);
		if (preg_match('/^all\b/ui', $args)) {
			if (!$ctx->isPrivate()) {
				throw new ValidationError('Список уровней — в личке: /level all');
			}
			return $this->listAllLevels($ctx);
		}

		$mentions = $ctx->message->mentionedUsers($this->bot->username);
		$rest = $args;
		$target = null;
		if ($mentions !== []) {
			$first = $mentions[0];
			$target = isset($first['user'])
				? $this->player_resolver->fromUser($first['user'])
				: $this->player_resolver->fromReference('@' . $first['username'], $ctx->actor);
			$rest = trim((string) preg_replace('/' . preg_quote($first['text'], '/') . '/u', '', $args, 1));
		} elseif ($ctx->message->reply_to_from !== null && !$ctx->message->reply_to_from->is_bot) {
			$target = $this->player_resolver->fromUser($ctx->message->reply_to_from);
		}

		$tokens = preg_split('/\s+/u', $rest, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		// Setting: /level @user 4 [balance_id]
		if ($target !== null && $tokens !== []) {
			if (!$ctx->is_owner) {
				throw new PermissionDenied(AccessPolicy::LEVELS_OWNER_ONLY);
			}
			return $this->setLevel($ctx, $target, $tokens);
		}

		if ($target === null && $args !== '') {
			// /level <IGN> or /level @user — look up without a mention entity
			$target = $this->player_resolver->fromReference($args, $ctx->actor);
		}

		if ($target === null) {
			$target = $ctx->actor;
			$own = true;
		} else {
			$own = $target->id === $ctx->actor->id;
		}
		return $this->showPlayerLevel($ctx, $target, $own);
	}

	/**
		@param list<string> $tokens level token(s) and optional balance id
	*/
	protected function setLevel(Context $ctx, Player $player, array $tokens): string {
		$balance_id = null;
		if (count($tokens) >= 2 && TournamentResolver::parseId((string) end($tokens)) !== null) {
			$balance_id = TournamentResolver::parseId((string) array_pop($tokens));
		}
		$level = Levels::parse(implode('', $tokens));
		if ($level === null) {
			throw new ValidationError('Уровень от 1 до 6 или название: ' . self::levelsHint());
		}

		$this->balances->setLevel($ctx->groupId(), $ctx->actor->id, $player->id, $level, $balance_id);
		return sprintf(
			'Записала: %s — %s (%d).',
			Html::e($player->mention()),
			Levels::name($level),
			$level,
		);
	}

	protected function showPlayerLevel(Context $ctx, Player $player, bool $own): string {
		$scale_id = $this->balances->viewScaleId();
		$explicit = $this->balances->explicitLevel($scale_id, $player->id);
		if ($explicit === null) {
			$body = $own
				? sprintf(
					'Уровень ещё не ставили — пока считаю %s (%d).',
					Levels::name(Levels::DEFAULT),
					Levels::DEFAULT,
				)
				: sprintf(
					'%s — уровень ещё не ставили, считаю %s (%d).',
					Html::e($player->plainLabel()),
					Levels::name(Levels::DEFAULT),
					Levels::DEFAULT,
				);
		} else {
			$body = $own
				? sprintf('Твой уровень: %s (%d)', Levels::name($explicit), $explicit)
				: sprintf('%s — %s (%d)', Html::e($player->plainLabel()), Levels::name($explicit), $explicit);
		}
		return $body . $this->levelFooter($ctx->is_owner);
	}

	protected function listAllLevels(Context $ctx): string {
		$scale_id = $this->balances->viewScaleId();
		$scale = $scale_id !== null ? $this->balances->findDefault() : null;
		if ($scale === null) {
			return 'Пока никому уровень не ставили 🙂' . $this->levelFooter($ctx->is_owner);
		}
		$ratings = $this->balances->effectiveRatings($scale->id);
		if ($ratings === []) {
			return 'Пока никому уровень не ставили 🙂' . $this->levelFooter($ctx->is_owner);
		}
		$rows = [];
		foreach ($ratings as $player_id => $rating) {
			$player = $this->players->find($player_id);
			$label = $player !== null ? $player->plainLabel() : '#' . $player_id;
			$rows[] = [
				'level' => $rating['level'],
				'label' => $label,
				'sort' => mb_strtolower($label),
			];
		}
		usort($rows, static function (array $a, array $b): int {
			return $b['level'] <=> $a['level'] ?: ($a['sort'] <=> $b['sort']);
		});
		$total = count($rows);
		$rows = array_slice($rows, 0, self::LEVEL_ALL_LIMIT);
		$lines = ['📊 <b>Уровни игроков</b>'];
		foreach ($rows as $row) {
			$lines[] = sprintf(
				'• %s — %s (%d)',
				Html::e($row['label']),
				Levels::name($row['level']),
				$row['level'],
			);
		}
		if ($total > self::LEVEL_ALL_LIMIT) {
			$lines[] = sprintf('<i>…и ещё %d</i>', $total - self::LEVEL_ALL_LIMIT);
		}
		return implode("\n", $lines) . $this->levelFooter($ctx->is_owner);
	}

	protected function levelFooter(bool $is_owner): string {
		$footer = "\n\nВсе уровни — /levels";
		if ($is_owner) {
			$footer .= "\nПоставить: /level @ник 4";
		}
		return $footer;
	}


	/**
		/nick — show own nick; /nick @user IGN — owner assigns. Users cannot set their own.
	*/
	protected function nickCommand(Context $ctx, string $args): string {
		if ($this->nicks === null) {
			throw new ValidationError('Ники пока недоступны.');
		}
		$args = trim($args);
		$mentions = $ctx->message->mentionedUsers($this->bot->username);
		$target = null;
		$rest = $args;
		if ($mentions !== []) {
			$first = $mentions[0];
			$target = isset($first['user'])
				? $this->player_resolver->fromUser($first['user'])
				: $this->player_resolver->fromReference('@' . $first['username'], $ctx->actor);
			$rest = trim((string) preg_replace('/' . preg_quote($first['text'], '/') . '/u', '', $args, 1));
		} elseif ($ctx->message->reply_to_from !== null && !$ctx->message->reply_to_from->is_bot) {
			$target = $this->player_resolver->fromUser($ctx->message->reply_to_from);
		}

		if ($target !== null && $rest !== '') {
			if (!$ctx->is_owner) {
				throw new PermissionDenied(AccessPolicy::OWNER_ONLY);
			}
			$result = $this->nicks->assignByOwner($ctx->actor->id, $target->id, $rest);
			$msg = sprintf(
				'Поставила ник «%s» игроку %s.',
				Html::e($result['player']->ign ?? $rest),
				Html::e($result['player']->mention()),
			);
			if ($result['previous_holder'] !== null) {
				$msg .= sprintf(
					' Раньше он был у %s — забрала.',
					Html::e($result['previous_holder']->mention()),
				);
			}
			return $msg;
		}

		if ($target !== null && $rest === '') {
			return $this->showNick($ctx, $target);
		}

		if ($args === '') {
			return $this->showNick($ctx, $ctx->actor);
		}

		// Bare IGN: owner may set own nick; everyone else is refused.
		if ($ctx->is_owner) {
			$result = $this->nicks->assignByOwner($ctx->actor->id, $ctx->actor->id, $args);
			return sprintf(
				'Поставила тебе ник «%s».',
				Html::e($result['player']->ign ?? $args),
			);
		}
		throw new ValidationError(
			'Ник привязывает организатор 🙂 Посмотреть свой: /nick',
		);
	}

	protected function showNick(Context $ctx, Player $player): string {
		$own = $player->id === $ctx->actor->id;
		if (!$player->hasIgn()) {
			$text = $own
				? 'Ник пока не привязан. Привязывает организатор 🙂'
				: sprintf('У %s ник пока не привязан.', Html::e($player->mention()));
		} elseif ($own) {
			$text = sprintf('Твой ник: <b>%s</b>', Html::e((string) $player->ign));
		} else {
			$text = sprintf(
				'%s — ник <b>%s</b>.',
				Html::e($player->mention()),
				Html::e((string) $player->ign),
			);
		}
		if ($ctx->is_owner && $own) {
			$text .= "\nПоставить другому: /nick @user НикВИгре";
		}
		return $text;
	}

	/**
		/balance [id]
	*/
	protected function showBalance(Context $ctx, string $args): string {
		$id = TournamentResolver::parseId($args);
		if ($id === null) {
			$own = $this->balance_repo->listByOwner($ctx->actor->id);
			$balance = $own[0] ?? $this->balances->findDefault();
			if ($balance === null) {
				throw new ValidationError('Шкал баланса пока нет.');
			}
		} else {
			$balance = $this->balances->get($id);
		}

		$rows = [];
		foreach ($this->balances->effectiveRatings($balance->id) as $player_id => $rating) {
			$player = $this->players->find($player_id);
			$rows[] = sprintf(
				'• %s — %s (%d)%s',
				Html::e($player !== null ? $player->mention() : '#' . $player_id),
				Levels::name($rating['level']),
				$rating['level'],
				$rating['balance_id'] !== $balance->id ? ' <i>унаследовано</i>' : '',
			);
		}
		$owner = $this->players->find($balance->owner_id);
		$header = sprintf(
			'<b>Шкала «%s»</b> (№%d, владелец %s%s)',
			Html::e($balance->name),
			$balance->id,
			Html::e($owner !== null ? $owner->mention() : '?'),
			$balance->parent_id !== null ? ', основана на №' . $balance->parent_id : '',
		);
		if ($rows === []) {
			return $header . "\nОценок пока нет. Все игроки считаются средними (3).";
		}
		return $header . "\n" . implode("\n", $rows) . "\n\nОстальные игроки — средние (3).";
	}

	/**
		Private chat: look up tournaments globally. In a group: candidates without an id stay group-local.
	*/
	protected function resolveScope(Context $ctx): ?int {
		return $ctx->isPrivate() ? null : $ctx->groupId();
	}

	/**
		@param list<string>|null $statuses
	*/
	protected function target(Context $ctx, string $args, ?array $statuses = null): Tournament {
		[$id] = self::splitId($args);
		return $this->resolver->resolve($this->resolveScope($ctx), $id, $statuses ?? self::MANAGEABLE);
	}

	/**
		@return array{0: ?int, 1: string} leading tournament id and the rest
	*/
	protected static function splitId(string $args): array {
		$args = trim($args);
		if (preg_match('/^([#№]?\d{1,9})(?:\s+(.*))?$/us', $args, $m)) {
			return [TournamentResolver::parseId($m[1]), trim($m[2] ?? '')];
		}
		return [null, $args];
	}

	protected static function intArg(string $value, string $what): int {
		if (!preg_match('/^\d{1,4}$/', trim($value))) {
			throw new ValidationError(sprintf('Не поняла %s: «%s». Нужно число.', $what, $value));
		}
		return (int) $value;
	}

	protected static function levelsHint(): string {
		$parts = [];
		foreach (Levels::all() as $level => $name) {
			$parts[] = "$level — $name";
		}
		return implode(', ', $parts);
	}

	protected function canCreate(Context $ctx): bool {
		if ($this->create_access !== null) {
			return $this->create_access->canCreate($ctx->actor->id);
		}
		return $ctx->is_admin || $ctx->is_owner;
	}

	/**
		Owner-only private chat: reply keyboard with Telegram user picker.
	*/
	protected function addUser(Context $ctx): string {
		if (!$ctx->isPrivate()) {
			return 'Команда /adduser работает только в личке со мной.';
		}
		$this->reply_extra = [
			'reply_markup' => [
				'keyboard' => [[
					[
						'text' => '👤 Выбрать игроков',
						'request_users' => [
							'request_id' => 1,
							'user_is_bot' => false,
							'max_quantity' => 10,
							'request_name' => true,
							'request_username' => true,
						],
					],
				]],
				'resize_keyboard' => true,
				'one_time_keyboard' => true,
			],
		];
		return 'Нажми кнопку ниже и выбери до 10 игроков — я добавлю их в базу '
			. '(с @username, если Telegram его отдаст).';
	}
}
