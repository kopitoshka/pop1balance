<?php

declare(strict_types=1);

namespace P1Bot\App;

use DateInterval;
use P1Bot\Domain\AccessPolicy;
use P1Bot\Domain\BalanceService;
use P1Bot\Domain\Exception\FieldError;
use P1Bot\Domain\Levels;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\NickService;
use P1Bot\Domain\PlayerAdmin;
use P1Bot\Domain\TeamService;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Repository\WebAppContextRepository;
use P1Bot\Support\Clock;
use P1Bot\Telegram\TelegramApi;
use P1Bot\Telegram\WebAppInitData;
use P1Bot\View\DeepLink;
use P1Bot\View\Html;
use P1Bot\View\WebAppUrl;

/**
	The owner's player card (public/webapp/player.html): nick, level, @username, preferred team
	name, merge a duplicate, delete. Owner only, everywhere: every API call checks the initData
	user against BOT_OWNER_TG_ID, a link or a token never grants access by itself.

	Two ways in, both end on the same page and the same API:
	- Direct Mini App link t.me/<bot>/<short name>?startapp=p<player id> (TELEGRAM_MINIAPP_SHORT_NAME,
	  set up once in @BotFather /newapp, URL public/webapp/app.html). One tap, also from a group.
	  The player id travels in the signed initData start_param, so it cannot be forged.
	- Fallback while no short name is set: t.me/<bot>?start=pe_<player id>. The bot answers the
	  owner in private with a web_app button that carries a short-lived context token.
*/
class PlayerForm {
	public const ACTION = 'player_form';
	public const TTL_SECONDS = 3600;
	public const DEEP_LINK_PREFIX = 'pe_';
	public const START_PARAM_PREFIX = 'p';
	public const SEARCH_LIMIT = 12;

	public const OPEN_BUTTON = '✏️ Открыть карточку';
	public const EXPIRED_TEXT = 'Карточка устарела. Закрой её и открой заново ✏️ из списка /players.';
	public const GONE_TEXT = 'Такого игрока уже нет. Обнови список /players.';
	public const UNAVAILABLE_TEXT = 'Карточка игрока сейчас недоступна. Попробуй чуть позже.';

	public function __construct(
		protected WebAppContextRepository $contexts,
		protected PlayerRepository $players,
		protected PlayerAdmin $admin,
		protected AccessPolicy $access,
		protected BalanceService $balances,
		protected TelegramApi $api,
		protected Clock $clock,
		protected string $bot_token,
		protected string $webhook_url,
		protected string $bot_username,
		protected string $miniapp_short_name = '',
	) {
	}

	public function isAvailable(): bool {
		return WebAppUrl::page($this->webhook_url, WebAppUrl::PLAYER_FORM) !== null;
	}

	/**
		A link to put in /players can work: a Direct Mini App is set up, or the web_app fallback is possible.
	*/
	public function canLink(): bool {
		return $this->bot_username !== '' && ($this->miniapp_short_name !== '' || $this->isAvailable());
	}

	/**
		The ✏️ link of a player: one tap with a Direct Mini App, otherwise the two-step deep link.
	*/
	public function editLink(int $player_id): ?string {
		if (!$this->canLink()) {
			return null;
		}
		if ($this->miniapp_short_name !== '') {
			return DeepLink::miniApp($this->bot_username, $this->miniapp_short_name, self::START_PARAM_PREFIX . $player_id);
		}
		return DeepLink::url($this->bot_username, self::DEEP_LINK_PREFIX . $player_id);
	}

	/**
		/start pe_<player id>: answers the owner with the web_app button of that card.
	*/
	public function continueFromStart(int $chat_id, Player $actor, string $payload, ?int $reply_to = null): bool {
		if (preg_match('/^' . self::DEEP_LINK_PREFIX . '(\d+)$/D', $payload, $m) !== 1) {
			return false;
		}
		if (!$this->access->isOwner($actor->id)) {
			$this->api->sendMessage($chat_id, Html::e(AccessPolicy::OWNER_ONLY), $reply_to);
			return true;
		}
		$player = $this->players->find((int) $m[1]);
		if ($player === null || $player->isDeleted()) {
			$this->api->sendMessage($chat_id, self::GONE_TEXT, $reply_to);
			return true;
		}
		if (!$this->isAvailable()) {
			$this->api->sendMessage($chat_id, self::UNAVAILABLE_TEXT, $reply_to);
			return true;
		}
		$token = $this->issue($actor, $chat_id, $player->id);
		$url = WebAppUrl::playerForm($this->webhook_url, $token);
		$this->api->sendMessage(
			$chat_id,
			'Карточка игрока <b>' . Html::e($player->name()) . '</b>:',
			$reply_to,
			['reply_markup' => ['inline_keyboard' => [[['text' => self::OPEN_BUTTON, 'web_app' => ['url' => (string) $url]]]]]],
		);
		return true;
	}

	/**
		@return array<string, mixed>
	*/
	public function bootstrap(string $init_data, string $token): array {
		[, $player] = $this->authorize($init_data, $token);
		$scale_id = $this->balances->viewScaleId();
		$explicit = $scale_id === null ? null : $this->balances->explicitLevel($scale_id, $player->id);
		$levels = [];
		foreach (Levels::all() as $level => $name) {
			$levels[] = ['level' => $level, 'name' => $name];
		}
		return [
			'ok' => true,
			'action' => self::ACTION,
			'player' => [
				'id' => $player->id,
				'title' => $player->name(),
				'ign' => $player->hasIgn() ? $player->ign : null,
				'username' => $player->username,
				'level' => $explicit,
				'level_name' => $explicit === null ? null : Levels::name($explicit),
				'preferred_team_name' => $this->players->preferredTeamName($player->id),
				'tg_bound' => $player->tg_user_id !== null,
				'tg_id' => $player->tg_user_id,
				'dm_ok' => $player->tg_user_id !== null && $this->players->dmOk($player->id),
				'is_owner' => $this->access->isOwner($player->id),
			],
			'levels' => $levels,
			'default_level' => Levels::DEFAULT,
			'default_level_name' => Levels::name(Levels::DEFAULT),
			'delete_blockers' => $this->admin->deleteBlockers($player->id),
			'limits' => [
				'ign_min' => NickService::MIN_LEN,
				'ign_max' => NickService::MAX_LEN,
				'team_name_max' => TeamService::MAX_NAME_LENGTH,
			],
		];
	}

	/**
		Players to merge this card into (not the card itself, never deleted ones).

		@return array{ok: true, players: list<array<string, mixed>>}
	*/
	public function search(string $init_data, string $token, string $query): array {
		[, $player] = $this->authorize($init_data, $token);
		$query = trim($query);
		$found = $query === ''
			? $this->players->listKnown(self::SEARCH_LIMIT, [$player->id])
			: $this->players->searchPartial($query, self::SEARCH_LIMIT, [$player->id]);
		$scale_id = $this->balances->viewScaleId();
		$out = [];
		foreach ($found as $candidate) {
			$explicit = $scale_id === null ? null : $this->balances->explicitLevel($scale_id, $candidate->id);
			$out[] = [
				'id' => $candidate->id,
				'title' => $candidate->name(),
				'username' => $candidate->username,
				'level' => $explicit ?? Levels::DEFAULT,
				'level_explicit' => $explicit !== null,
				'tg_bound' => $candidate->tg_user_id !== null,
				'problem' => $this->admin->mergeProblem($player, $candidate),
			];
		}
		return ['ok' => true, 'players' => $out];
	}

	/**
		@param array<string, mixed> $form
		@return array{ok: true, close: true}
	*/
	public function save(string $init_data, string $token, array $form): array {
		[$actor, $player] = $this->authorize($init_data, $token);
		try {
			$this->admin->save($actor->id, $player->id, $form);
		} catch (FieldError $e) {
			throw new WebAppFormError($e->getMessage(), $e->field, WebAppFormError::INVALID, $e->fields);
		}
		return $this->done($token);
	}

	/**
		@return array{ok: true, close: true}
	*/
	public function delete(string $init_data, string $token): array {
		[$actor, $player] = $this->authorize($init_data, $token);
		$this->admin->delete($actor->id, $player->id);
		return $this->done($token);
	}

	/**
		This card is the duplicate: it is folded into the card with id $into_id.

		@return array{ok: true, close: true}
	*/
	public function merge(string $init_data, string $token, int $into_id): array {
		[$actor, $player] = $this->authorize($init_data, $token);
		$this->admin->merge($actor->id, $player->id, $into_id);
		return $this->done($token);
	}

	/**
		@return array{ok: true, close: true}
	*/
	protected function done(string $token): array {
		if ($token !== '') {
			$this->contexts->delete($token);
		}
		return ['ok' => true, 'close' => true];
	}

	/**
		Who is asking and which card: the initData user must be the owner; the card comes from the
		context token (button) or from the signed start_param p<id> (Direct Mini App link).

		@return array{0: Player, 1: Player} actor, card
	*/
	protected function authorize(string $init_data, string $token): array {
		$this->contexts->purgeExpired($this->clock->now());
		$parsed = WebAppInitData::parse($init_data, $this->bot_token, $this->clock->now()->getTimestamp());
		$actor = $this->players->findByTelegramId($parsed['user_id']);
		if ($actor === null || !$this->access->isOwner($actor->id)) {
			throw new WebAppFormError(AccessPolicy::OWNER_ONLY, '', WebAppFormError::FORBIDDEN);
		}
		$player_id = 0;
		$token = trim($token);
		if ($token !== '') {
			$ctx = preg_match('/^[a-f0-9]{32}$/D', $token) === 1 ? $this->contexts->find($token, $this->clock->now()) : null;
			if ($ctx === null || $ctx['action'] !== self::ACTION || $ctx['actor_player_id'] !== $actor->id) {
				throw new WebAppFormError(self::EXPIRED_TEXT, '', WebAppFormError::EXPIRED);
			}
			$player_id = (int) ($ctx['payload']['player_id'] ?? 0);
		} else {
			$param = (string) ($parsed['query']['start_param'] ?? '');
			if (preg_match('/^' . self::START_PARAM_PREFIX . '(\d+)$/D', $param, $m) === 1) {
				$player_id = (int) $m[1];
			}
		}
		$player = $player_id > 0 ? $this->players->find($player_id) : null;
		if ($player === null || $player->isDeleted()) {
			throw new WebAppFormError(self::GONE_TEXT, '', WebAppFormError::EXPIRED);
		}
		return [$actor, $player];
	}

	protected function issue(Player $actor, int $chat_id, int $player_id): string {
		$this->contexts->purgeExpired($this->clock->now());
		$token = bin2hex(random_bytes(16));
		$now = $this->clock->now();
		$this->contexts->create(
			$token,
			$actor->id,
			$chat_id,
			self::ACTION,
			['player_id' => $player_id],
			$now->add(new DateInterval('PT' . self::TTL_SECONDS . 'S')),
			$now,
		);
		return $token;
	}
}
