<?php

declare(strict_types=1);

namespace P1Bot\Handlers;

use P1Bot\App\Announcer;
use P1Bot\App\CreateAccess;
use P1Bot\App\CreateTournamentForm;
use P1Bot\App\WebAppPicker;
use P1Bot\App\DraftCards;
use P1Bot\App\NeedGroupChoice;
use P1Bot\App\PrivateGroups;
use P1Bot\App\PlayerResolver;
use P1Bot\App\TournamentListing;
use P1Bot\App\TournamentResolver;
use P1Bot\Domain\AccessPolicy;
use P1Bot\Domain\BalanceService;
use P1Bot\Domain\EditTournament;
use P1Bot\Domain\Exception\DomainError;
use P1Bot\Domain\Exception\InvalidLevel;
use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Domain\Exception\PlayerSuggestError;
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
	protected const PUBLIC = ['help', 'start', 'tournaments', 'history', 'info', 'level', 'levels', 'nick', 'adduser', 'team'];

	/**
		«уровень», «уровень турнира», «максимальный уровень», «макс. уровень» — one field (old «эталон» still works).
	*/
	protected const LEVEL_FIELD = '/^(?:(?:максимальный|макс\.?)\s*уровень(?:\s+турнира)?|уровень(?:\s+турнира)?|эталон|reference|level)(?=\s|$)\s*(.*)$/us';
	protected const CREATE = ['create_tournament'];
	protected const MANAGE = ['edit', 'cancel', 'open'];
	protected const ADMIN = ['create_tournament', 'edit', 'cancel', 'open'];

	/**
		Owner only for now (scales are hidden from users, see AccessPolicy::LEVELS_OWNER_ONLY).
	*/
	protected const OWNER = [];

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
		protected ?\P1Bot\App\TeamCards $team_cards = null,
		protected ?\P1Bot\Repository\TeamRepository $team_repo = null,
		protected ?WebAppPicker $webapp = null,
	) {
	}

	/**
		Extra sendMessage options for the reply of the current command (e.g. «open» buttons).

		@var array<string, mixed>
	*/
	protected array $reply_extra = [];

	protected ?CreateTournamentForm $create_form = null;

	public function setCreateForm(?CreateTournamentForm $create_form): void {
		$this->create_form = $create_form;
	}

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
			// /create_tournament: CreateAccess (shared group with owner, or ANYONE_CAN_CREATE). Manage: author|owner only.
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
		} catch (PlayerSuggestError $e) {
			$reply = Html::e($e->getMessage());
			$this->reply_extra = $e->replyMarkup();
			if ($command === 'level' && $ctx->isPrivate()) {
				$this->attachLevelPicker($ctx);
			}
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
				if ($ctx->isPrivate() && $ctx->is_owner) {
					return $this->ownerLevels($ctx);
				}
				return Texts::levels(false);
			case 'info':
				[$id] = self::splitId($args);
				$t = $this->resolver->resolve($this->resolveScope($ctx), $id, TournamentStatus::infoable());
				return $this->sendAllButLast($ctx, $this->announcer->infoMessages($t));
			case 'create_tournament':
				return $this->createTournament($ctx);
			case 'edit':
				return $this->edit($ctx, $args);
			case 'open':
				return $this->open($ctx, $args);
			case 'team':
				return $this->showTeam($ctx);
			case 'cancel':
				$target = $this->target($ctx, $args);
				if ($target->status === TournamentStatus::DRAFT) {
					$snap = $this->tournaments->deleteDraft($ctx->actor->id, $target->id);
					return sprintf('Черновик #%d удалён', $snap['id']);
				}
				$t = $this->tournaments->cancel($ctx->actor->id, $target->id);
				$this->announcer->refresh($t->id);
				$this->announcer->renumber($t->id);
				return sprintf('Турнир %s отменён. Жаль!', Html::b($t->title));
			case 'level':
				return $this->levelCommand($ctx, $args);
			case 'nick':
				return $this->nickCommand($ctx, $args);
		}
		return '';
	}

	protected function listTournaments(Context $ctx): string {
		if ($ctx->isPrivate() && $this->groups !== null) {
			$groups = $this->groups->all();
			$messages = $this->listing->privateMessages($groups, $ctx->actor->id, $ctx->is_owner);
			$this->reply_extra = $this->withCreateButton(TournamentListing::openButtons(
				$this->listing->privateTournaments($groups, $ctx->actor->id, $ctx->is_owner),
				$groups,
			), $ctx);
			if ($messages === []) {
				return 'Сейчас активных турниров нет.' . $this->createHint($ctx);
			}
			return $this->sendAllButLast($ctx, $messages);
		}
		// The group list never shows drafts: drafts live in the organizer's private chat.
		$messages = $this->listing->messages($ctx->groupId(), false);
		$this->reply_extra = $this->withCreateButton(TournamentListing::deepLinkButtons(
			$this->listing->privateTournaments([], $ctx->actor->id, false),
			$this->bot->username,
		), $ctx);
		if ($messages === []) {
			return 'Сейчас активных турниров нет.' . $this->createHint($ctx);
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
		/create_tournament: private — a message with the form button; group — a link to the private chat.
	*/
	protected function createTournament(Context $ctx): string {
		if ($this->create_form === null) {
			return Html::e(CreateTournamentForm::UNAVAILABLE_TEXT);
		}
		if ($ctx->isPrivate()) {
			$this->create_form->sendOffer($ctx->chatId(), $ctx->actor, $ctx->message->message_id);
		} else {
			$this->create_form->sendGroupRedirect($ctx->chatId(), $ctx->message->message_id);
		}
		return '';
	}

	/**
		«➕ Создать турнир» under the tournament list (only for those who may create).

		@param array<string, mixed> $extra
		@return array<string, mixed>
	*/
	protected function withCreateButton(array $extra, Context $ctx): array {
		if ($this->create_form === null) {
			return $extra;
		}
		return $this->create_form->appendListButton($extra, $ctx->actor, $ctx->isPrivate());
	}

	/**
		Tail of «Сейчас активных турниров нет.» for those who may create.
	*/
	protected function createHint(Context $ctx): string {
		if (!$this->canCreate($ctx)) {
			return '';
		}
		$button = isset($this->reply_extra['reply_markup']['inline_keyboard']);
		if ($ctx->isPrivate()) {
			return $button
				? ' Создать новый — кнопкой ниже или просто напиши, какой турнир нужен.'
				: ' Создать новый: /create_tournament или просто напиши, какой турнир нужен.';
		}
		return $button
			? ' Создать новый можно в личке — кнопка ниже.'
			: ' Создать новый можно в личке: /create_tournament.';
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
					throw new ValidationError("Не указан уровень турнира.\n\n" . Texts::levelUsage($t->number, $t->team_size));
				}
				$edit = new EditTournament(null, null, null, $value);
				break;
			case 'лимит':
			case 'limit':
				$edit = new EditTournament(null, null, null, null, null, self::intArg($value, 'лимит участников'));
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
			$rest = trim((string) preg_replace('/' . preg_quote($first['text'], '/') . '/u', '', $args, 1));
			$tokens_preview = preg_split('/\s+/u', $rest, -1, PREG_SPLIT_NO_EMPTY) ?: [];
			$resume = $this->levelResume($ctx, $tokens_preview);
			$target = isset($first['user'])
				? $this->player_resolver->fromUser($first['user'])
				: $this->player_resolver->fromReference(
					'@' . $first['username'],
					$ctx->actor,
					$resume,
					$ctx->chatId(),
				);
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
			// /level @user [level…] — only when the first token is an @mention.
			// Bare IGN may contain spaces ("Zoloto on heat") and must stay intact.
			if (preg_match('/^@([A-Za-z][A-Za-z0-9_]{2,31})(?:\s+(.*))?$/us', $args, $m)) {
				$rest_tokens = [];
				if (isset($m[2]) && trim($m[2]) !== '') {
					$rest_tokens = preg_split('/\s+/u', trim($m[2]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
				}
				$resume = $this->levelResume($ctx, $rest_tokens);
				$target = $this->player_resolver->fromReference(
					'@' . $m[1],
					$ctx->actor,
					$resume,
					$ctx->chatId(),
				);
				if ($rest_tokens !== []) {
					if (!$ctx->is_owner) {
						throw new PermissionDenied(AccessPolicy::LEVELS_OWNER_ONLY);
					}
					return $this->setLevel($ctx, $target, $rest_tokens);
				}
			} else {
				$target = $this->player_resolver->fromReference(
					$args,
					$ctx->actor,
					['kind' => 'level_show', 'owner_only' => false],
					$ctx->chatId(),
				);
			}
		}

		if ($target === null) {
			$target = $ctx->actor;
			$own = true;
		} else {
			$own = $target->id === $ctx->actor->id;
		}
		$text = $this->showPlayerLevel($ctx, $target, $own);
		if ($ctx->isPrivate() && $args === '') {
			$this->attachLevelPicker($ctx);
		}
		return $text;
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

	protected function attachLevelPicker(Context $ctx): void {
		if ($this->webapp === null) {
			return;
		}
		$this->reply_extra = $this->webapp->appendLevelButton(
			$this->reply_extra,
			$ctx->actor,
			$ctx->chatId(),
			$ctx->is_owner,
		);
	}


	/**
		/nick — show own; /nick IGN — set own (private); /nick @user IGN — owner assigns.
	*/
	protected function nickCommand(Context $ctx, string $args): string {
		if ($this->nicks === null) {
			throw new ValidationError('Ники пока недоступны.');
		}
		if (!$ctx->isPrivate()) {
			$this->reply_extra = DeepLink::button(
				Messages::GROUP_REDIRECT_BUTTON,
				DeepLink::url($this->bot->username),
			);
			return Messages::GROUP_REDIRECT;
		}
		$args = trim($args);
		$mentions = $ctx->message->mentionedUsers($this->bot->username);
		$target = null;
		$rest = $args;
		$owner_form = false;
		if ($mentions !== []) {
			$owner_form = true;
			$first = $mentions[0];
			$rest = trim((string) preg_replace('/' . preg_quote($first['text'], '/') . '/u', '', $args, 1));
			$resume = $rest !== ''
				? ['kind' => 'nick_set', 'ign' => $rest, 'owner_only' => true]
				: ['kind' => 'nick_show', 'owner_only' => false];
			$target = isset($first['user'])
				? $this->player_resolver->fromUser($first['user'])
				: $this->player_resolver->fromReference(
					'@' . $first['username'],
					$ctx->actor,
					$resume,
					$ctx->chatId(),
				);
		} elseif ($ctx->message->reply_to_from !== null && !$ctx->message->reply_to_from->is_bot) {
			$owner_form = true;
			$target = $this->player_resolver->fromUser($ctx->message->reply_to_from);
		} elseif (preg_match('/^@([A-Za-z][A-Za-z0-9_]{2,31})(?:\s+(.*))?$/us', $args, $am)) {
			$owner_form = true;
			$rest = trim($am[2] ?? '');
			$resume = $rest !== ''
				? ['kind' => 'nick_set', 'ign' => $rest, 'owner_only' => true]
				: ['kind' => 'nick_show', 'owner_only' => false];
			$target = $this->player_resolver->fromReference(
				'@' . $am[1],
				$ctx->actor,
				$resume,
				$ctx->chatId(),
			);
		}

		if ($owner_form && $target !== null && $rest !== '') {
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

		if ($owner_form && $target !== null && $rest === '') {
			return $this->showNick($ctx, $target);
		}

		if ($args === '') {
			return $this->showNick($ctx, $ctx->actor);
		}

		// Whole remainder = own IGN (spaces allowed, 2–32 chars).
		$player = $this->nicks->setOwn($ctx->actor->id, $args);
		return sprintf('Поставила тебе ник «%s».', Html::e((string) $player->ign));
	}

	protected function showNick(Context $ctx, Player $player): string {
		$own = $player->id === $ctx->actor->id;
		if (!$player->hasIgn()) {
			$text = $own
				? 'Ник пока не привязан. Поставить: <code>/nick игровой_ник</code>'
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
		if ($own) {
			$text .= "\nСменить: <code>/nick игровой_ник</code>";
		}
		if ($ctx->is_owner && $own) {
			$text .= "\nПоставить другому: /nick @user НикВИгре";
		}
		return $text;
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


	/**
		@param list<string> $tokens
		@return array<string, mixed>
	*/
	protected function levelResume(Context $ctx, array $tokens): array {
		if ($tokens === []) {
			return ['kind' => 'level_show', 'owner_only' => false];
		}
		$balance_id = null;
		$copy = $tokens;
		if (count($copy) >= 2 && TournamentResolver::parseId((string) end($copy)) !== null) {
			$balance_id = TournamentResolver::parseId((string) array_pop($copy));
		}
		$level = Levels::parse(implode('', $copy));
		if ($level === null) {
			return ['kind' => 'level_show', 'owner_only' => false];
		}
		return [
			'kind' => 'level_set',
			'level' => $level,
			'balance_id' => $balance_id,
			'group_id' => $ctx->groupId(),
			'owner_only' => true,
		];
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
		Private chat: reply keyboard with Telegram user picker (any user).
		In groups GroupChat redirects to private before this runs.
	*/

	/**
		/team — private team card(s) for open/upcoming tournaments.
	*/
	protected function showTeam(Context $ctx): string {
		if (!$ctx->isPrivate()) {
			$this->reply_extra = DeepLink::button(
				Messages::GROUP_REDIRECT_BUTTON,
				DeepLink::url($this->bot->username),
			);
			return Messages::GROUP_REDIRECT;
		}
		if ($this->team_repo === null || $this->team_cards === null) {
			return 'Команды пока недоступны.';
		}
		$teams = $this->team_repo->listTeamsForPlayer(
			$ctx->actor->id,
			[\P1Bot\Domain\TournamentStatus::REGISTRATION, \P1Bot\Domain\TournamentStatus::CLOSED],
		);
		if ($teams === []) {
			return "Сейчас ты не в команде ни на одном открытом турнире.\n"
				. 'Собрать: открой турнир (/tournaments) → «🛡 Собрать команду», '
				. 'или напиши мне «запиши команду …».';
		}
		if (count($teams) > 1) {
			$rows = [];
			$lines = ['Ты в нескольких командах — выбери:'];
			foreach ($teams as $i => $team) {
				$t = $this->tournaments->get($team->tournament_id);
				$letter = $team->letter ?? '?';
				$lines[] = $letter . '. «' . Html::e($team->name) . '» — ' . Html::e($t->title) . ' (№' . $t->id . ')';
				$rows[] = [[
					'text' => mb_substr($letter . '. 🛡 ' . $team->name . ' · ' . $t->title, 0, 64),
					'callback_data' => 'tcard:' . $team->id,
				]];
			}
			$this->reply_extra = ['reply_markup' => ['inline_keyboard' => $rows]];
			return implode("\n", $lines);
		}
		$entry = $this->teamsGet($teams[0]->id);
		$t = $this->tournaments->get($entry->team->tournament_id);
		$this->team_cards->send($ctx->chatId(), $t, $entry, $ctx->actor, $ctx->message->message_id);
		return '';
	}

	/**
		Owner /levels in private: rated players grouped by level, then the reference help.
	*/
	protected function ownerLevels(Context $ctx): string {
		$scale = $this->balances->findDefault();
		$chunks = [];
		if ($scale !== null) {
			$by_level = [1 => [], 2 => [], 3 => [], 4 => [], 5 => [], 6 => []];
			foreach ($this->balances->effectiveRatings($scale->id) as $player_id => $rating) {
				$player = $this->players->find($player_id);
				if ($player === null || $player->tg_user_id === null) {
					continue;
				}
				$level = (int) $rating['level'];
				if (!isset($by_level[$level])) {
					continue;
				}
				$by_level[$level][] = $player;
			}
			$lines = ['📋 <b>Уровни игроков</b>'];
			$any = false;
			foreach ($by_level as $level => $list) {
				if ($list === []) {
					continue;
				}
				$any = true;
				usort($list, static fn ($a, $b) => strcmp($a->plainLabel(), $b->plainLabel()));
				$lines[] = '';
				$lines[] = '<b>' . Levels::name($level) . ' (' . $level . ')</b> — ' . count($list);
				foreach ($list as $player) {
					$lines[] = '• ' . $player->lineupLabel()
						. ($player->username !== null ? ' · @' . Html::e($player->username) : '');
				}
			}
			if (!$any) {
				$lines[] = '';
				$lines[] = 'Пока никому не поставлен уровень — все считаются средними (3).';
			}
			$chunks = $this->splitTelegramMessages(implode("\n", $lines));
			foreach (array_slice($chunks, 0, -1) as $chunk) {
				$this->api->sendMessage($ctx->chatId(), $chunk, $ctx->message->message_id);
			}
			$preamble = $chunks !== [] ? end($chunks) . "\n\n" : '';
			return $preamble . Texts::levels(true);
		}
		return Texts::levels(true);
	}

	/**
		@param list<string> $noop
		@return list<string>
	*/
	protected function splitTelegramMessages(string $text, int $limit = 3900): array {
		if (mb_strlen($text) <= $limit) {
			return [$text];
		}
		$parts = [];
		$buf = '';
		foreach (explode("\n", $text) as $line) {
			$candidate = $buf === '' ? $line : $buf . "\n" . $line;
			if (mb_strlen($candidate) > $limit && $buf !== '') {
				$parts[] = $buf;
				$buf = $line;
			} else {
				$buf = $candidate;
			}
		}
		if ($buf !== '') {
			$parts[] = $buf;
		}
		return $parts !== [] ? $parts : [$text];
	}

	protected function teamsGet(int $team_id): \P1Bot\Domain\Model\TeamEntry {
		// Prefer TeamService when available via tournaments path — use team_repo members.
		$team = $this->team_repo->find($team_id) ?? throw new \P1Bot\Domain\Exception\NotFound('Такой команды нет.');
		return new \P1Bot\Domain\Model\TeamEntry($team, $this->team_repo->members($team_id));
	}

	protected function addUser(Context $ctx): string {
		if (!$ctx->isPrivate()) {
			$this->reply_extra = DeepLink::button(
				Messages::GROUP_REDIRECT_BUTTON,
				DeepLink::url($this->bot->username),
			);
			return Messages::GROUP_REDIRECT;
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
