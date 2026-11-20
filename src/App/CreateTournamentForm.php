<?php

declare(strict_types=1);

namespace P1Bot\App;

use DateInterval;
use DateTimeImmutable;
use P1Bot\Domain\CreateTournament;
use P1Bot\Domain\Exception\DomainError;
use P1Bot\Domain\Exception\InvalidLevel;
use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Domain\Levels;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\MoscowTime;
use P1Bot\Domain\TournamentService;
use P1Bot\Repository\PendingActionRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Repository\WebAppContextRepository;
use P1Bot\Support\Clock;
use P1Bot\Support\DialogLog;
use P1Bot\Support\RuPlural;
use P1Bot\Telegram\TelegramApi;
use P1Bot\Telegram\TgUser;
use P1Bot\Telegram\WebAppInitData;
use P1Bot\View\DeepLink;
use P1Bot\View\Html;
use P1Bot\View\WebAppUrl;

/**
	Tournament creation through the Mini App form (public/webapp/create.html).
	/create_tournament and «➕ Создать турнир» on the tournament list open it; in groups they
	lead to the private chat (start=create). The form only creates a draft: the draft card
	with «Публикуем?» comes to the private chat, publishing stays on that card.
*/
class CreateTournamentForm {
	public const ACTION = 'create_tournament';
	public const COMMAND = 'create_tournament';
	public const DEEP_LINK = 'create';

	/**
		How long a form button stays valid. Longer than the picker: the button also sits
		under the tournament list, and filling the form takes a while.
	*/
	public const TTL_SECONDS = 21600;

	public const DEFAULT_TEAM_SIZE = 3;
	public const DEFAULT_TIME = '20:00';

	public const FORM_BUTTON = '📝 Создать турнир';
	public const LIST_BUTTON = '➕ Создать турнир';
	public const GROUP_BUTTON = '✉️ Продолжить в личке';
	public const CANCEL_BUTTON = '✖️ Отмена';
	public const CANCEL_DATA = 'tcreatex:0';

	public const OFFER_TEXT = 'Открой форму и заполни поля. После создания пришлю карточку черновика: '
		. 'проверишь и опубликуешь.';
	public const GROUP_TEXT = 'Создать турнир можно в личке, там откроется форма.';
	public const CANCELLED_TEXT = 'Хорошо, не создаём. Если передумаешь — /create_tournament.';
	public const UNAVAILABLE_TEXT = 'Форма создания сейчас недоступна. Напиши мне, какой турнир нужен, '
		. 'например «создай турнир на субботу в 20:00, тройки, уровень 10», — я соберу черновик.';
	public const EXPIRED_TEXT = 'Форма устарела. Закрой её и открой заново: /create_tournament или кнопка '
		. '«➕ Создать турнир» под списком турниров.';

	public function __construct(
		protected WebAppContextRepository $contexts,
		protected PlayerRepository $players,
		protected TournamentService $tournaments,
		protected CreateAccess $create_access,
		protected PrivateGroups $groups,
		protected Announcer $announcer,
		protected DraftCards $drafts,
		protected PendingActionRepository $pending,
		protected TelegramApi $api,
		protected Clock $clock,
		protected string $bot_token,
		protected string $webhook_url,
		protected string $bot_username,
		protected ?DialogLog $dialogs = null,
	) {
	}

	public function isAvailable(): bool {
		return WebAppUrl::page($this->webhook_url, WebAppUrl::CREATE_FORM) !== null;
	}

	public function canCreate(Player $actor): bool {
		return $this->create_access->canCreate($actor->id);
	}

	public function deepLink(): string {
		return DeepLink::url($this->bot_username, self::DEEP_LINK);
	}

	/**
		web_app button that opens the form (private chat only), or null without a Mini App URL.

		@return array<string, mixed>|null
	*/
	public function formButton(Player $actor, string $text = self::FORM_BUTTON): ?array {
		if (!$this->isAvailable()) {
			return null;
		}
		$token = $this->issueToken($actor);
		if ($token === null) {
			return null;
		}
		$url = WebAppUrl::createForm($this->webhook_url, $token);
		if ($url === null) {
			return null;
		}
		return ['text' => $text, 'web_app' => ['url' => $url]];
	}

	/**
		«➕ Создать турнир» for the tournament list: only for those who may create.
		Private chat: opens the form; elsewhere: a deep link into the private chat.

		@return list<array<string, mixed>>|null one keyboard row
	*/
	public function listRow(Player $actor, bool $private): ?array {
		if (!$this->canCreate($actor)) {
			return null;
		}
		if (!$private) {
			return [['text' => self::LIST_BUTTON, 'url' => $this->deepLink()]];
		}
		$btn = $this->formButton($actor, self::LIST_BUTTON);
		return $btn === null ? null : [$btn];
	}

	/**
		Adds the list row to sendMessage options (keeps existing buttons first).

		@param array<string, mixed> $extra
		@return array<string, mixed>
	*/
	public function appendListButton(array $extra, Player $actor, bool $private): array {
		$row = $this->listRow($actor, $private);
		if ($row === null) {
			return $extra;
		}
		$rows = $extra['reply_markup']['inline_keyboard'] ?? [];
		$rows[] = $row;
		$extra['reply_markup']['inline_keyboard'] = $rows;
		return $extra;
	}

	/**
		/create_tournament in private: a short message with the form button and «✖️ Отмена».
		The caller has already checked CreateAccess.
	*/
	public function sendOffer(int $chat_id, Player $actor, ?int $reply_to = null): int {
		$btn = $this->formButton($actor);
		if ($btn === null) {
			return $this->api->sendMessage($chat_id, Html::e(self::UNAVAILABLE_TEXT), $reply_to);
		}
		return $this->api->sendMessage($chat_id, Html::e(self::OFFER_TEXT), $reply_to, [
			'reply_markup' => [
				'inline_keyboard' => [
					[$btn],
					[['text' => self::CANCEL_BUTTON, 'callback_data' => self::CANCEL_DATA]],
				],
			],
		]);
	}

	/**
		/create_tournament in a group: the form opens only in private.
	*/
	public function sendGroupRedirect(int $chat_id, ?int $reply_to = null): int {
		return $this->api->sendMessage(
			$chat_id,
			Html::e(self::GROUP_TEXT),
			$reply_to,
			DeepLink::button(self::GROUP_BUTTON, $this->deepLink()),
		);
	}

	/**
		Everything the page needs: limits, defaults, level names, Moscow «now».

		@return array<string, mixed>
	*/
	public function bootstrap(string $init_data, string $token): array {
		$this->authorize($init_data, $token);
		$now_msk = $this->clock->now()->setTimezone(MoscowTime::zone());
		$levels = [];
		foreach (Levels::all() as $level => $name) {
			$levels[] = ['level' => $level, 'name' => $name];
		}
		return [
			'ok' => true,
			'action' => self::ACTION,
			'now_msk' => $now_msk->format('Y-m-d H:i:s'),
			'default_date' => $now_msk->modify('+1 day')->format('Y-m-d'),
			'default_time' => self::DEFAULT_TIME,
			'registration_close_minutes' => $this->tournaments->registrationCloseMinutes(),
			'title_max' => TournamentService::TITLE_MAX_LENGTH,
			'team_size' => [
				'min' => TournamentService::MIN_TEAM_SIZE,
				'max' => TournamentService::MAX_TEAM_SIZE,
				'default' => self::DEFAULT_TEAM_SIZE,
			],
			'max_players' => [
				'default' => $this->tournaments->defaultMaxPlayers(),
				'max' => TournamentService::MAX_PLAYERS_LIMIT,
			],
			'levels' => $levels,
			'level_min' => Levels::MIN,
			'level_max' => Levels::MAX,
			'default_level' => Levels::DEFAULT,
		];
	}

	/**
		Validates the form, creates the draft and sends its card to the private chat.

		@param array<string, mixed> $input title, date (Y-m-d), time (H:i), team_size,
			level_mode (players|sum), levels (list of 1..6), points, max_players
		@return array{ok: true, close: true, tournament_id: int}
	*/
	public function submit(string $init_data, string $token, array $input): array {
		[$actor, $ctx, $user] = $this->authorize($init_data, $token);
		$create = $this->parse($input, $actor);
		$summary = $this->summary($create);
		if ($this->dialogs !== null) {
			// The card goes to the user's dialog log like any other reply.
			$this->dialogs->begin($user, $ctx['chat_id'], 'private', '');
			$this->dialogs->user('[форма] Создать черновик: ' . $summary);
		}
		try {
			try {
				$create->chat_id = $this->groups->originForCreate($actor->id);
				$t = $this->tournaments->create($create);
			} catch (PermissionDenied $e) {
				throw new WebAppFormError($e->getMessage(), '', WebAppFormError::FORBIDDEN);
			} catch (InvalidLevel $e) {
				throw new WebAppFormError($e->getMessage(), 'level');
			} catch (DomainError $e) {
				throw new WebAppFormError($e->getMessage());
			}
			$this->announcer->renumber($t->id);
			$this->pending->clearChat($actor->id, $ctx['chat_id']);
			$this->drafts->show($t->id, $ctx['chat_id'], null, $actor->id, [
				['role' => 'user', 'content' => 'Создаю турнир через форму: ' . $summary],
			]);
		} finally {
			if ($this->dialogs !== null) {
				$this->dialogs->end();
			}
		}
		return ['ok' => true, 'close' => true, 'tournament_id' => $t->id];
	}

	/**
		Form fields => CreateTournament (chat_id is set later). Collects all field errors.

		@param array<string, mixed> $input
	*/
	public function parse(array $input, Player $actor): CreateTournament {
		$errors = [];
		$title = trim((string) ($input['title'] ?? ''));
		if ($title === '') {
			$errors['title'] = 'Введи название турнира';
		} elseif (mb_strlen($title) > TournamentService::TITLE_MAX_LENGTH) {
			$errors['title'] = sprintf('Название — не длиннее %d символов', TournamentService::TITLE_MAX_LENGTH);
		}

		$starts_at = $this->parseStart((string) ($input['date'] ?? ''), (string) ($input['time'] ?? ''), $errors);

		$team_size = self::intValue($input['team_size'] ?? null);
		if (
			$team_size === null
			|| $team_size < TournamentService::MIN_TEAM_SIZE
			|| $team_size > TournamentService::MAX_TEAM_SIZE
		) {
			$errors['team_size'] = sprintf(
				'В команде может быть от %d до %d игроков',
				TournamentService::MIN_TEAM_SIZE,
				TournamentService::MAX_TEAM_SIZE,
			);
			$team_size = null;
		}

		$reference = null;
		if ($team_size !== null) {
			$reference = $this->parseLevel($input, $team_size, $errors);
		}

		$max_players = self::intValue($input['max_players'] ?? null);
		$min_players = $team_size ?? TournamentService::MIN_TEAM_SIZE;
		if ($max_players === null || $max_players < $min_players || $max_players > TournamentService::MAX_PLAYERS_LIMIT) {
			$errors['max_players'] = sprintf(
				'Лимит участников — от %d до %d',
				$min_players,
				TournamentService::MAX_PLAYERS_LIMIT,
			);
		}

		if ($errors !== []) {
			$field = (string) array_key_first($errors);
			throw new WebAppFormError($errors[$field], $field, WebAppFormError::INVALID, $errors);
		}
		return new CreateTournament(
			null,
			$actor->id,
			$title,
			MoscowTime::toInput($starts_at),
			(int) $team_size,
			$reference,
			null,
			$max_players,
		);
	}

	/**
		Date and time are always Moscow time, whatever the device timezone.

		@param array<string, string> $errors
	*/
	protected function parseStart(string $date, string $time, array &$errors): DateTimeImmutable {
		$fallback = $this->clock->now();
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($date), $d) !== 1
			|| !checkdate((int) $d[2], (int) $d[3], (int) $d[1])
		) {
			$errors['start'] = 'Выбери дату начала';
			return $fallback;
		}
		if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', trim($time), $tm) !== 1) {
			$errors['start'] = 'Выбери время начала';
			return $fallback;
		}
		$starts_at = MoscowTime::parse(sprintf('%s-%s-%s %s:%s', $d[1], $d[2], $d[3], $tm[1], $tm[2]));
		if ($starts_at <= $this->clock->now()) {
			$errors['start'] = 'Это время уже прошло. Выбери будущее время по МСК';
		}
		return $starts_at;
	}

	/**
		Reference team for TournamentService: "3,3,5" (per player) or "10" (sum).

		@param array<string, mixed> $input
		@param array<string, string> $errors
	*/
	protected function parseLevel(array $input, int $team_size, array &$errors): ?string {
		$mode = (string) ($input['level_mode'] ?? '');
		if ($mode === 'players') {
			$raw = $input['levels'] ?? null;
			if (!is_array($raw) || count($raw) !== $team_size) {
				$errors['level'] = sprintf(
					'Укажи уровень каждого игрока: %d %s',
					$team_size,
					RuPlural::choose($team_size, ['игрок', 'игрока', 'игроков']),
				);
				return null;
			}
			$levels = [];
			foreach (array_values($raw) as $value) {
				$level = self::intValue($value);
				if ($level === null || !Levels::isValid($level)) {
					$errors['level'] = sprintf('Уровень игрока — от %d до %d', Levels::MIN, Levels::MAX);
					return null;
				}
				$levels[] = $level;
			}
			return Levels::formatReferenceTeam($levels);
		}
		if ($mode === 'sum') {
			$points = self::intValue($input['points'] ?? null);
			$min = $team_size * Levels::MIN;
			$max = $team_size * Levels::MAX;
			if ($points === null || $points < $min || $points > $max) {
				$errors['level'] = sprintf('Сумма баллов для этой команды — от %d до %d', $min, $max);
				return null;
			}
			return (string) $points;
		}
		$errors['level'] = 'Выбери, как задать уровень: по игрокам или суммой';
		return null;
	}

	/**
		@return array{0: Player, 1: array{token: string, actor_player_id: int, chat_id: int, action: string, payload: array<string, mixed>}, 2: TgUser}
	*/
	protected function authorize(string $init_data, string $token): array {
		$now = $this->clock->now();
		$this->contexts->purgeExpired($now);
		try {
			$parsed = WebAppInitData::parse($init_data, $this->bot_token, $now->getTimestamp());
		} catch (DomainError $e) {
			throw new WebAppFormError(self::EXPIRED_TEXT, '', WebAppFormError::EXPIRED);
		}
		$token = trim($token);
		if ($token === '' || preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
			throw new WebAppFormError(self::EXPIRED_TEXT, '', WebAppFormError::EXPIRED);
		}
		$ctx = $this->contexts->find($token, $now);
		if ($ctx === null || $ctx['action'] !== self::ACTION) {
			throw new WebAppFormError(self::EXPIRED_TEXT, '', WebAppFormError::EXPIRED);
		}
		$actor = $this->players->find($ctx['actor_player_id']);
		if ($actor === null || $actor->tg_user_id === null || $actor->tg_user_id !== $parsed['user_id']) {
			throw new WebAppFormError('Эта форма открыта для другого человека.', '', WebAppFormError::FORBIDDEN);
		}
		if (!$this->create_access->canCreate($actor->id)) {
			throw new WebAppFormError(CreateAccess::REFUSAL, '', WebAppFormError::FORBIDDEN);
		}
		$user = new TgUser(
			$parsed['user_id'],
			false,
			$parsed['username'] ?? $actor->username,
			$parsed['first_name'] !== '' ? $parsed['first_name'] : $actor->display_name,
		);
		return [$actor, $ctx, $user];
	}

	/**
		A fresh form context for the actor's private chat (chat id = Telegram user id).
	*/
	public function issueToken(Player $actor): ?string {
		if ($actor->tg_user_id === null) {
			return null;
		}
		return $this->issue($actor, $actor->tg_user_id);
	}

	protected function issue(Player $actor, int $chat_id): string {
		$now = $this->clock->now();
		$this->contexts->purgeExpired($now);
		$token = bin2hex(random_bytes(16));
		$this->contexts->create(
			$token,
			$actor->id,
			$chat_id,
			self::ACTION,
			[],
			$now->add(new DateInterval('PT' . self::TTL_SECONDS . 'S')),
			$now,
		);
		return $token;
	}

	/**
		One line for the draft conversation history (the AI sees it when the user replies to the card).
	*/
	protected function summary(CreateTournament $c): string {
		return sprintf(
			'%s, %s МСК, игроков в команде: %d, уровень: %s, лимит: %d',
			$c->title,
			$c->starts_at_msk,
			$c->team_size,
			(string) $c->reference_team,
			(int) $c->max_players,
		);
	}

	protected static function intValue(mixed $value): ?int {
		if (is_int($value)) {
			return $value;
		}
		if (is_string($value) && preg_match('/^\s*\d{1,9}\s*$/', $value) === 1) {
			return (int) trim($value);
		}
		if (is_float($value) && floor($value) === $value) {
			return (int) $value;
		}
		return null;
	}
}
