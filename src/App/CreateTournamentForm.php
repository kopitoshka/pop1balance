<?php

declare(strict_types=1);

namespace P1Bot\App;

use DateInterval;
use DateTimeImmutable;
use P1Bot\Domain\CreateTournament;
use P1Bot\Domain\EditTournament;
use P1Bot\Domain\Exception\DomainError;
use P1Bot\Domain\Exception\InvalidLevel;
use P1Bot\Domain\Exception\NotFound;
use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Domain\Levels;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\MoscowTime;
use P1Bot\Domain\TournamentMaps;
use P1Bot\Domain\TournamentService;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Repository\PendingActionRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Repository\WebAppContextRepository;
use P1Bot\Support\Clock;
use P1Bot\Support\DialogLog;
use P1Bot\Support\ImageStore;
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
	«✏️ Поправить» on the draft card opens the same form in edit mode (prefilled, «Сохранить»);
	it saves through TournamentService::edit and shows the updated card again.
*/
class CreateTournamentForm {
	public const ACTION = 'create_tournament';
	public const COMMAND = 'create_tournament';
	public const DEEP_LINK = 'create';
	public const ACTION_EDIT = 'edit_tournament';
	public const MODE_EDIT = 'edit';

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
	public const EDIT_BUTTON = '✏️ Поправить';
	public const EDIT_PUBLISHED_BUTTON = '✏️ Изменить турнир';
	/** Statuses the form edits: the draft and published tournaments (TournamentService::edit). */
	public const EDITABLE = [TournamentStatus::DRAFT, TournamentStatus::REGISTRATION, TournamentStatus::CLOSED, TournamentStatus::RUNNING];

	public const OFFER_TEXT = 'Открой форму и заполни поля. После создания пришлю карточку черновика: '
		. 'проверишь и опубликуешь.';
	public const GROUP_TEXT = 'Создать турнир можно в личке, там откроется форма.';
	public const CANCELLED_TEXT = 'Хорошо, не создаём. Если передумаешь — /create_tournament.';
	public const UNAVAILABLE_TEXT = 'Форма создания сейчас недоступна, попробуй чуть позже.';
	public const EXPIRED_TEXT = 'Форма устарела. Закрой её и открой заново: /create_tournament или кнопка '
		. '«➕ Создать турнир» под списком турниров.';
	public const EDIT_EXPIRED_TEXT = 'Форма устарела. Закрой её и открой заново кнопкой на свежей карточке: '
		. '«✏️ Поправить» у черновика или «✏️ Изменить турнир» у турнира в /tournaments.';
	public const EDIT_OFFER_TEXT = 'Открой форму и поправь черновик. Потом пришлю обновлённую карточку.';
	public const NOT_DRAFT_TEXT = 'Турнир «%s» уже %s, менять его в форме нельзя.';
	public const LEVEL_LOCKED_HINT = 'Размер команды уже не поменять: на турнир есть заявки.';
	public const SIZE_LOCKED_HINT = self::LEVEL_LOCKED_HINT;
	public const UPDATED_TEXT = 'Обновила турнир «%s», анонсы поправила.';

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

	protected ?PrivateViews $views = null;
	protected ?TournamentImages $images = null;

	public function setImages(?TournamentImages $images): void {
		$this->images = $images;
	}

	public function setViews(?PrivateViews $views): void {
		$this->views = $views;
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
		«✏️ Поправить» for a draft card shown in $chat_id to $actor_id: a web_app button in private,
		a deep link into the private chat elsewhere (web_app buttons work only in private).
		Null without a Mini App URL: the card then keeps the old callback button.

		@return array<string, mixed>|null
	*/
	public function editButton(Tournament $t, int $chat_id, int $actor_id, ?string $text = null): ?array {
		if (!$this->isAvailable()) {
			return null;
		}
		$text = $text ?? ($t->status === TournamentStatus::DRAFT ? self::EDIT_BUTTON : self::EDIT_PUBLISHED_BUTTON);
		if ($chat_id < 0) {
			return ['text' => $text, 'url' => DeepLink::tournament($this->bot_username, $t->id)];
		}
		$actor = $this->players->find($actor_id);
		if ($actor === null || $actor->tg_user_id === null) {
			return null;
		}
		$token = $this->issue($actor, $chat_id, self::ACTION_EDIT, ['tournament_id' => $t->id]);
		$url = WebAppUrl::editForm($this->webhook_url, $token);
		return $url === null ? null : ['text' => $text, 'web_app' => ['url' => $url]];
	}

	/**
		Old cards still carry the callback «Поправить» (fix:<id>): answer with a form button.
		Returns false when the form is unavailable.
	*/
	public function sendEditOffer(int $chat_id, Tournament $t, Player $actor, ?int $reply_to = null): bool {
		$btn = $this->editButton($t, $chat_id, $actor->id);
		if ($btn === null) {
			return false;
		}
		$this->api->sendMessage($chat_id, Html::e(self::EDIT_OFFER_TEXT), $reply_to, [
			'reply_markup' => ['inline_keyboard' => [[$btn]]],
		]);
		return true;
	}

	/**
		Everything the page needs: limits, defaults, level names, Moscow «now».

		@return array<string, mixed>
	*/
	public function bootstrap(string $init_data, string $token): array {
		[$actor] = $this->authorize($init_data, $token);
		$out = $this->settings();
		$out['default_title'] = '';
		$last = $this->tournaments->latestByOrganizer($actor->id);
		if ($last !== null) {
			$values = $this->values($last);
			$out['default_title'] = $values['title'];
			$out['default_map'] = $values['map'];
			$out['default_description'] = $values['description'];
			$out['default_time'] = $values['time'];
			$out['default_level_mode'] = $values['level_mode'];
			$out['default_levels'] = $values['levels'];
			$out['default_points'] = $values['points'];
			$out['team_size']['default'] = $values['team_size'];
			$out['max_players']['default'] = $values['max_players'];
		}
		return $out;
	}

	/**
		Edit mode: the same settings plus the draft's current values to prefill the form.

		@return array<string, mixed>
	*/
	public function editBootstrap(string $init_data, string $token): array {
		[, , , $t] = $this->authorizeEdit($init_data, $token);
		$out = $this->settings();
		$out['action'] = self::ACTION_EDIT;
		$out['mode'] = self::MODE_EDIT;
		$out['tournament'] = $this->values($t);
		$out['published'] = $t->status !== TournamentStatus::DRAFT;
		// Teams or solo players are in: team size stays as it was; the level may still change.
		$size_locked = $out['published'] && $this->tournaments->hasEntries($t->id);
		$out['size_locked'] = $size_locked;
		$out['size_locked_text'] = $size_locked ? self::SIZE_LOCKED_HINT : null;
		$out['level_locked'] = false;
		$out['level_locked_text'] = null;
		return $out;
	}

	/**
		@return array<string, mixed>
	*/
	protected function settings(): array {
		$now_msk = $this->clock->now()->setTimezone(MoscowTime::zone());
		$levels = [];
		foreach (Levels::all() as $level => $name) {
			$levels[] = ['level' => $level, 'name' => $name];
		}
		return [
			'ok' => true,
			'action' => self::ACTION,
			'mode' => 'create',
			'now_msk' => $now_msk->format('Y-m-d H:i:s'),
			'default_date' => $now_msk->modify('+1 day')->format('Y-m-d'),
			'default_time' => self::DEFAULT_TIME,
			'default_map' => null,
			'default_description' => '',
			'default_level_mode' => null,
			'default_levels' => [],
			'default_points' => null,
			'registration_close_minutes' => $this->tournaments->registrationCloseMinutes(),
			'title_max' => TournamentService::TITLE_MAX_LENGTH,
			'maps' => TournamentMaps::VALUES,
			'map_placeholder' => TournamentMaps::PLACEHOLDER,
			'description_max' => TournamentService::DESCRIPTION_MAX_LENGTH,
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
			'pair_bonus' => [
				'min_level' => Levels::pairBonusMinLevel(),
				'offset' => Levels::pairBonusOffset(),
			],
			'images' => $this->images?->settings() ?? ['enabled' => false],
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
		$opening = WebAppContextRepository::openingId($input['opening_id'] ?? null);
		$replay_id = $this->createdOpening($ctx, $opening);
		if ($replay_id !== null) {
			return ['ok' => true, 'close' => true, 'tournament_id' => $replay_id];
		}
		$create = $this->parse($input, $actor);
		$image_plan = $this->imagePlan($input, $ctx['payload']);
		$summary = $this->summary($create);
		if ($this->dialogs !== null) {
			// The card goes to the user's dialog log like any other reply.
			$this->dialogs->begin($user, $ctx['chat_id'], 'private', '');
			$this->dialogs->user('[форма] Создать черновик: ' . $summary);
		}
		try {
			try {
				$t = $this->contexts->transactional(function () use ($token, $create, $actor, $opening): Tournament {
					$locked = $this->contexts->lock($token, $this->clock->now());
					if ($locked === null) {
						throw new WebAppFormError(self::EXPIRED_TEXT, '', WebAppFormError::EXPIRED);
					}
					$replay_id = $this->createdOpening($locked, $opening);
					if ($replay_id !== null) {
						return $this->tournaments->get($replay_id);
					}
					$create->chat_id = $this->groups->originForCreate($actor->id);
					$t = $this->tournaments->create($create);
					if ($opening !== null) {
						$payload = $locked['payload'];
						$payload['openings'] = is_array($payload['openings'] ?? null) ? $payload['openings'] : [];
						$payload['openings'][$opening] = $t->id;
						$this->contexts->updatePayload($token, $payload);
					}
					return $t;
				});
			} catch (PermissionDenied $e) {
				throw new WebAppFormError($e->getMessage(), '', WebAppFormError::FORBIDDEN);
			} catch (InvalidLevel $e) {
				throw new WebAppFormError($e->getMessage(), 'level');
			} catch (WebAppFormError $e) {
				throw $e;
			} catch (DomainError $e) {
				throw new WebAppFormError($e->getMessage());
			}
			$t = $this->commitImage($actor, $t, $image_plan, $token);
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
		Draft values in the form's terms: MSK date/time, level per player (reference team)
		or as a sum (max_points).

		@return array<string, mixed>
	*/
	public function values(Tournament $t): array {
		$start = $t->starts_at->setTimezone(MoscowTime::zone());
		$levels = [];
		if ($t->reference_team !== null) {
			try {
				$levels = Levels::parseReferenceTeam($t->reference_team);
			} catch (DomainError $e) {
				$levels = [];
			}
		}
		$per_player = $levels !== [] && count($levels) === $t->team_size;
		return [
			'id' => $t->id,
			'number' => $this->announcer->number($t)->number,
			'title' => $t->title,
			'date' => $start->format('Y-m-d'),
			'time' => $start->format('H:i'),
			'team_size' => $t->team_size,
			'level_mode' => $per_player ? 'players' : 'sum',
			'levels' => $per_player ? $levels : [],
			'points' => $t->max_points ?? $t->team_size * Levels::DEFAULT,
			'max_players' => $t->max_players,
			'map' => $t->map,
			'description' => $t->description,
			'image_url' => $this->images !== null && $this->images->enabled() ? $this->images->url($t->image_path) : null,
		];
	}

	/**
		Edit mode submit: validates like creation, saves through TournamentService::edit
		(author or owner) and shows the updated draft card again.

		@param array<string, mixed> $input the same fields as submit()
		@return array{ok: true, close: true, tournament_id: int}
	*/
	public function editSubmit(string $init_data, string $token, array $input): array {
		[$actor, $ctx, $user, $t] = $this->authorizeEdit($init_data, $token);
		$form = $this->parse($input, $actor, $t);
		$image_plan = $this->imagePlan($input, $ctx['payload']);
		$summary = $this->summary($form);
		$level_same = $form->team_size === $t->team_size
			&& $form->reference_team === ($t->reference_team ?? ($t->max_points !== null ? (string) $t->max_points : null));
		$published = $t->status !== TournamentStatus::DRAFT;
		$start_same = $form->starts_at_msk === MoscowTime::toInput($t->starts_at);
		$edit = new EditTournament(
			title: $form->title,
			// A published tournament keeps its start (and reminders) unless the time really changed.
			starts_at_msk: $published && $start_same ? null : $form->starts_at_msk,
			team_size: $level_same ? null : $form->team_size,
			reference_team: $level_same ? null : $form->reference_team,
			max_players: $form->max_players,
			map: $form->map,
			description: $form->description,
			clear_description: $form->description === null,
		);
		if ($this->dialogs !== null) {
			$this->dialogs->begin($user, $ctx['chat_id'], 'private', '');
			$this->dialogs->user('[форма] Сохранить ' . ($published ? 'турнир' : 'черновик') . ' №' . $t->id . ': ' . $summary);
		}
		try {
			try {
				$t = $this->tournaments->edit($actor->id, $t->id, $edit);
			} catch (PermissionDenied $e) {
				throw new WebAppFormError($e->getMessage(), '', WebAppFormError::FORBIDDEN);
			} catch (InvalidLevel $e) {
				throw new WebAppFormError($e->getMessage(), 'level');
			} catch (DomainError $e) {
				throw new WebAppFormError($e->getMessage());
			}
			$t = $this->commitImage($actor, $t, $image_plan, $token);
			$this->announcer->renumber($t->id); // a new start time may change the order
			$this->pending->clearChat($actor->id, $ctx['chat_id']);
			if ($published) {
				// Same as the old /edit: announcements are updated in place, then a fresh card.
				$this->announcer->refresh($t->id);
				$this->api->sendMessage($ctx['chat_id'], Html::e(sprintf(self::UPDATED_TEXT, $t->title)));
				$this->views?->tournament($ctx['chat_id'], $t, $actor);
			} else {
				$this->drafts->show($t->id, $ctx['chat_id'], null, $actor->id, [
					['role' => 'user', 'content' => 'Правлю турнир через форму: ' . $summary],
				]);
			}
		} finally {
			if ($this->dialogs !== null) {
				$this->dialogs->end();
			}
		}
		return ['ok' => true, 'close' => true, 'tournament_id' => $t->id];
	}

	/**
		The picture field of a form: an upload (multipart op tournament_image) stores a processed file
		and remembers it in the form context as pending_image. Nothing is attached to a tournament
		until the form is saved with image = «new»; a replaced or abandoned upload is deleted.
		Same access as the form itself: create — those who may create; edit — the author or a superadmin.

		@param array<string, mixed> $file the PHP $_FILES entry: tmp_name, error, size
		@return array{ok: true, url: string}
	*/
	public function imageUpload(string $init_data, string $token, array $file): array {
		[$actor] = $this->authorizeImage($init_data, $token);
		if ($this->images === null || !$this->images->enabled()) {
			throw new WebAppFormError(TournamentImages::DISABLED_TEXT, 'image');
		}
		$limit = $this->images->store()->maxBytes();
		$error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
		if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
			throw new WebAppFormError(sprintf(
				'Файл не прошёл по размеру. Выбери картинку полегче (до %s).',
				ImageStore::megabytes($limit),
			), 'image');
		}
		if ($error === UPLOAD_ERR_NO_FILE) {
			throw new WebAppFormError('Файл не выбран.', 'image');
		}
		$path = (string) ($file['tmp_name'] ?? '');
		if ($error !== UPLOAD_ERR_OK || $path === '' || !is_file($path)) {
			throw new WebAppFormError('Не получилось загрузить файл. Попробуй ещё раз.', 'image');
		}
		// One byte more than the limit is enough to tell that the file is too big.
		$bytes = (string) file_get_contents($path, false, null, 0, $limit + 1);
		try {
			$name = $this->images->save($bytes);
		} catch (WebAppFormError $e) {
			throw $e;
		} catch (DomainError $e) {
			throw new WebAppFormError($e->getMessage(), 'image');
		}
		$current = $this->contexts->find(trim($token), $this->clock->now());
		$payload = $current['payload'] ?? [];
		$previous = is_string($payload['pending_image'] ?? null) ? $payload['pending_image'] : null;
		$payload['pending_image'] = $name;
		$this->contexts->updatePayload(trim($token), $payload);
		if ($previous !== null && $previous !== $name) {
			$this->images->discard($previous);
		}
		return ['ok' => true, 'url' => (string) $this->images->url($name)];
	}

	/**
		Create or edit context of the token, whichever it is, with that form's own access check.

		@return array{0: Player, 1: array{token: string, actor_player_id: int, chat_id: int, action: string, payload: array<string, mixed>}}
	*/
	protected function authorizeImage(string $init_data, string $token): array {
		$token = trim($token);
		$peek = preg_match('/^[a-f0-9]{32}$/', $token) === 1 ? $this->contexts->find($token, $this->clock->now()) : null;
		if ($peek !== null && $peek['action'] === self::ACTION_EDIT) {
			[$actor, $ctx] = $this->authorizeEdit($init_data, $token);
		} else {
			[$actor, $ctx] = $this->authorize($init_data, $token);
		}
		return [$actor, $ctx];
	}

	/**
		What the saved form asks for the picture: «keep» (default), «new» (the pending upload) or «remove».
		Checked before anything is saved. With pictures off the field is ignored.

		@param array<string, mixed> $input
		@param array<string, mixed> $payload context payload
		@return array{action: string}
	*/
	protected function imagePlan(array $input, array $payload): array {
		if ($this->images === null || !$this->images->enabled()) {
			return ['action' => 'keep'];
		}
		$choice = (string) ($input['image'] ?? 'keep');
		if (!in_array($choice, ['new', 'remove'], true)) {
			return ['action' => 'keep'];
		}
		if ($choice === 'new' && !$this->images->store()->exists(is_string($payload['pending_image'] ?? null) ? $payload['pending_image'] : null)) {
			throw new WebAppFormError(TournamentImages::STALE_TEXT, 'image');
		}
		return ['action' => $choice];
	}

	/**
		Applies the plan after the tournament is saved: attaches the pending upload (deleting the old
		file) or clears the picture; an unused upload is deleted.

		@param array{action: string} $plan
	*/
	protected function commitImage(Player $actor, Tournament $t, array $plan, string $token): Tournament {
		if ($this->images === null) {
			return $t;
		}
		$token = trim($token);
		$current = $this->contexts->find($token, $this->clock->now());
		$payload = $current['payload'] ?? [];
		$pending = is_string($payload['pending_image'] ?? null) ? $payload['pending_image'] : null;
		$old = $t->image_path;
		try {
			if ($plan['action'] === 'new' && $pending !== null) {
				$t = $this->tournaments->setImage($actor->id, $t->id, $pending);
				$pending = null;
				if ($old !== null) {
					$this->images->discard($old);
				}
			} elseif ($plan['action'] === 'remove') {
				$t = $this->tournaments->setImage($actor->id, $t->id, null);
				$this->images->discard($old);
			}
		} catch (PermissionDenied $e) {
			throw new WebAppFormError($e->getMessage(), '', WebAppFormError::FORBIDDEN);
		}
		if ($pending !== null) {
			$this->images->discard($pending);
		}
		if ($current !== null && isset($payload['pending_image'])) {
			unset($payload['pending_image']);
			$this->contexts->updatePayload($token, $payload);
		}
		return $t;
	}

	/**
		Form fields => CreateTournament (chat_id is set later). Collects all field errors.

		@param array<string, mixed> $input
	*/
	public function parse(array $input, Player $actor, ?Tournament $existing = null): CreateTournament {
		$errors = [];
		$title = trim((string) ($input['title'] ?? ''));
		if ($title === '') {
			$errors['title'] = 'Введи название турнира';
		} elseif (mb_strlen($title) > TournamentService::TITLE_MAX_LENGTH) {
			$errors['title'] = sprintf('Название — не длиннее %d символов', TournamentService::TITLE_MAX_LENGTH);
		}

		$map = trim((string) ($input['map'] ?? ''));
		if ($map === '') {
			$errors['map'] = TournamentMaps::REQUIRED_TEXT;
			$map = '';
		} elseif (!TournamentMaps::isValid($map)) {
			$errors['map'] = TournamentMaps::UNKNOWN_TEXT;
		}

		$description = str_replace("\r\n", "\n", (string) ($input['description'] ?? ''));
		$description = str_replace("\0", '', $description);
		$description = trim($description);
		if (mb_strlen($description) > TournamentService::DESCRIPTION_MAX_LENGTH) {
			$errors['description'] = sprintf(
				'Описание — не длиннее %d символов',
				TournamentService::DESCRIPTION_MAX_LENGTH,
			);
		}

		$date = (string) ($input['date'] ?? '');
		$time = (string) ($input['time'] ?? '');
		$same_start = $existing !== null && $existing->status !== TournamentStatus::DRAFT
			&& $date . ' ' . $time === MoscowTime::toInput($existing->starts_at);
		// Keep a published tournament's start, even after it has passed. Only a new time must be future.
		$starts_at = $same_start ? $existing->starts_at : $this->parseStart($date, $time, $errors);

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
			$map !== '' && TournamentMaps::isValid($map) ? $map : null,
			$description !== '' ? $description : null,
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
			$max = Levels::maxTeamPoints($team_size);
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
	/**
		Draft already created by this form opening, if it is still there.
		@param array<string, mixed> $ctx
	*/
	protected function createdOpening(array $ctx, ?string $opening): ?int {
		if ($opening === null) {
			return null;
		}
		$openings = $ctx['payload']['openings'] ?? null;
		if (!is_array($openings) || !isset($openings[$opening])) {
			return null;
		}
		$id = (int) $openings[$opening];
		if ($id <= 0) {
			return null;
		}
		try {
			$this->tournaments->get($id);
			return $id;
		} catch (NotFound $e) {
			return null;
		}
	}

	protected function authorize(string $init_data, string $token): array {
		[$actor, $ctx, $user] = $this->authorizeContext($init_data, $token, self::ACTION, self::EXPIRED_TEXT);
		if (!$this->create_access->canCreate($actor->id)) {
			throw new WebAppFormError(CreateAccess::REFUSAL, '', WebAppFormError::FORBIDDEN);
		}
		return [$actor, $ctx, $user];
	}

	/**
		Edit mode: the context names the tournament; only its author or the owner, until finished/cancelled.

		@return array{0: Player, 1: array{token: string, actor_player_id: int, chat_id: int, action: string, payload: array<string, mixed>}, 2: TgUser, 3: Tournament}
	*/
	protected function authorizeEdit(string $init_data, string $token): array {
		[$actor, $ctx, $user] = $this->authorizeContext($init_data, $token, self::ACTION_EDIT, self::EDIT_EXPIRED_TEXT);
		$id = self::intValue($ctx['payload']['tournament_id'] ?? null);
		try {
			$t = $this->tournaments->get((int) $id);
		} catch (DomainError $e) {
			throw new WebAppFormError('Этого турнира больше нет.', '', WebAppFormError::NOT_DRAFT);
		}
		try {
			$this->tournaments->assertCanManage($actor->id, $t->id);
		} catch (PermissionDenied $e) {
			throw new WebAppFormError($e->getMessage(), '', WebAppFormError::FORBIDDEN);
		}
		if (!in_array($t->status, self::EDITABLE, true)) {
			throw new WebAppFormError(
				sprintf(self::NOT_DRAFT_TEXT, $t->title, TournamentStatus::label($t->status)),
				'',
				WebAppFormError::NOT_DRAFT,
			);
		}
		return [$actor, $ctx, $user, $t];
	}

	/**
		initData signature, a live token of $action and the same Telegram user.

		@return array{0: Player, 1: array{token: string, actor_player_id: int, chat_id: int, action: string, payload: array<string, mixed>}, 2: TgUser}
	*/
	protected function authorizeContext(string $init_data, string $token, string $action, string $expired_text): array {
		$now = $this->clock->now();
		$this->contexts->purgeExpired($now);
		try {
			$parsed = WebAppInitData::parse($init_data, $this->bot_token, $now->getTimestamp());
		} catch (DomainError $e) {
			throw new WebAppFormError($expired_text, '', WebAppFormError::EXPIRED);
		}
		$token = trim($token);
		if ($token === '' || preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
			throw new WebAppFormError($expired_text, '', WebAppFormError::EXPIRED);
		}
		$ctx = $this->contexts->find($token, $now);
		if ($ctx === null || $ctx['action'] !== $action) {
			throw new WebAppFormError($expired_text, '', WebAppFormError::EXPIRED);
		}
		$actor = $this->players->find($ctx['actor_player_id']);
		if ($actor === null || $actor->tg_user_id === null || $actor->tg_user_id !== $parsed['user_id']) {
			throw new WebAppFormError('Эта форма открыта для другого человека.', '', WebAppFormError::FORBIDDEN);
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
		return $this->issue($actor, $actor->tg_user_id, self::ACTION, []);
	}

	/**
		@param array<string, mixed> $payload
	*/
	protected function issue(Player $actor, int $chat_id, string $action, array $payload): string {
		$now = $this->clock->now();
		$this->contexts->purgeExpired($now);
		$token = bin2hex(random_bytes(16));
		$this->contexts->create(
			$token,
			$actor->id,
			$chat_id,
			$action,
			$payload,
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
			'%s, %s, %s МСК, игроков в команде: %d, уровень: %s, лимит: %d',
			$c->title,
			$c->map ?? 'без карты',
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
