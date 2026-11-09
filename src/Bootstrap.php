<?php

declare(strict_types=1);

namespace P1Bot;

use P1Bot\Ai\AiClient;
use P1Bot\Ai\Assistant;
use P1Bot\Ai\Dispatcher;
use P1Bot\Ai\OpenAiClient;
use P1Bot\App\Announcer;
use P1Bot\App\CreateAccess;
use P1Bot\App\GroupChat;
use P1Bot\App\CronRunner;
use P1Bot\App\DraftCards;
use P1Bot\App\GroupRedirect;
use P1Bot\App\TeamDisbandNotifier;
use P1Bot\App\PlayerResolver;
use P1Bot\App\PrivateGroups;
use P1Bot\App\PrivateViews;
use P1Bot\App\TournamentListing;
use P1Bot\App\TournamentResolver;
use P1Bot\Domain\AccessPolicy;
use P1Bot\Domain\BalanceService;
use P1Bot\Domain\TeamService;
use P1Bot\Domain\TournamentService;
use P1Bot\Handlers\AdminCommands;
use P1Bot\Handlers\CallbackHandler;
use P1Bot\Handlers\MessageHandler;
use P1Bot\Handlers\PrivateChatHandler;
use P1Bot\Handlers\UpdateRouter;
use P1Bot\Http\CurlHttpClient;
use P1Bot\Http\HttpClient;
use P1Bot\Repository\AdminRepository;
use P1Bot\Repository\BalanceRepository;
use P1Bot\Repository\ChatRepository;
use P1Bot\Repository\EditedReplyRepository;
use P1Bot\Repository\GroupNoticeRepository;
use P1Bot\Repository\ConversationRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Repository\SoloPlayerRepository;
use P1Bot\Repository\TeamRepository;
use P1Bot\Repository\TournamentAnnouncementRepository;
use P1Bot\Repository\TournamentRepository;
use P1Bot\Support\Clock;
use P1Bot\Support\RandomSource;
use P1Bot\Support\SystemRandom;
use P1Bot\Support\Database;
use P1Bot\Support\DialogLog;
use P1Bot\Support\Logger;
use P1Bot\Telegram\BotApi;
use P1Bot\Telegram\BotIdentity;
use P1Bot\Telegram\ChatAdminSync;
use P1Bot\Telegram\DialogLoggingApi;
use P1Bot\Telegram\TelegramApi;

/**
	Hand-wired service container (no framework).
*/
class Bootstrap {
	public PlayerRepository $players;
	public ChatRepository $chats;
	public AdminRepository $admins;
	public BalanceRepository $balance_repo;
	public TournamentRepository $tournament_repo;
	public TeamRepository $team_repo;
	public SoloPlayerRepository $solo_repo;
	public ConversationRepository $conversations;

	public AccessPolicy $access;
	public BalanceService $balances;
	public TournamentService $tournaments;
	public TeamService $teams;

	public Logger $logger;
	public DialogLog $dialogs;
	public HttpClient $http;
	public BotIdentity $bot;
	public TelegramApi $telegram;
	public ChatAdminSync $admin_sync;
	public UpdateRouter $router;
	public Announcer $announcer;
	public DraftCards $drafts;
	public TournamentListing $listing;
	public PlayerResolver $player_resolver;
	public TournamentResolver $tournament_resolver;
	public Dispatcher $dispatcher;
	public ?Assistant $assistant = null;
	public PrivateGroups $private_groups;
	public CreateAccess $create_access;
	public TeamDisbandNotifier $disband_notifier;
	public RandomSource $random;
	public GroupRedirect $group_redirect;
	public ?GroupChat $group_chat = null;
	public TournamentAnnouncementRepository $announcements;

	/**
		@var array<string, mixed>
	*/
	public array $config;

	/**
		@param array<string, mixed> $config see config/config.php; missing keys get defaults
	*/
	public function __construct(
		public Database $db,
		public Clock $clock,
		array $config,
		?HttpClient $http = null,
		?Logger $logger = null,
	) {
		$this->config = $config;
		$this->logger = $logger ?? Logger::null();
		$this->http = $http ?? new CurlHttpClient();

		$this->players = new PlayerRepository($db);
		$this->chats = new ChatRepository($db);
		$this->admins = new AdminRepository($db);
		$this->balance_repo = new BalanceRepository($db);
		$this->tournament_repo = new TournamentRepository($db);
		$this->team_repo = new TeamRepository($db);
		$this->solo_repo = new SoloPlayerRepository($db);
		$this->conversations = new ConversationRepository($db);

		$owner_tg_id = (int) ($config['owner_tg_id'] ?? 0);
		$this->access = new AccessPolicy(
			$this->admins,
			$this->players,
			$owner_tg_id,
			(bool) ($config['tournament']['anyone_can_create'] ?? false),
		);
		$this->balances = new BalanceService($this->balance_repo, $this->players, $this->access, $db, $clock);
		$registration_close_minutes = (int) ($config['tournament']['registration_close_minutes'] ?? 20);
		$this->tournaments = new TournamentService(
			$this->tournament_repo,
			$this->team_repo,
			$this->solo_repo,
			$this->balance_repo,
			$this->access,
			$db,
			$clock,
			(int) ($config['tournament']['default_max_players'] ?? 24),
			$registration_close_minutes,
		);
		$this->teams = new TeamService(
			$this->team_repo,
			$this->solo_repo,
			$this->tournament_repo,
			$this->players,
			$this->balances,
			$this->access,
			$db,
			$clock,
			$registration_close_minutes,
		);

		$token = (string) ($config['telegram']['token'] ?? '');
		$this->bot = new BotIdentity(
			BotApi::botIdFromToken($token),
			(string) ($config['telegram']['username'] ?? ''),
			$owner_tg_id,
		);
		$this->dialogs = new DialogLog($this->dialogDir(), $clock);
		// Every reply during a user interaction also goes to that user's dialog log.
		$this->telegram = new DialogLoggingApi(new BotApi($this->http, $token, 15, $this->logger), $this->dialogs);
		$this->admin_sync = new ChatAdminSync(
			$this->telegram,
			$this->chats,
			$this->admins,
			$this->players,
			$clock,
			$this->bot,
			$this->logger,
			(int) ($config['admins_cache_minutes'] ?? 10),
		);

		$this->player_resolver = new PlayerResolver($this->players, $clock);
		$this->tournament_resolver = new TournamentResolver($this->tournament_repo);
		$this->announcements = new TournamentAnnouncementRepository($db);
		$this->announcer = new Announcer(
			$this->telegram,
			$this->tournaments,
			$this->players,
			$this->bot,
			$this->logger,
			$this->tournament_resolver,
			$this->chats,
			$this->announcements,
		);
		$this->listing = new TournamentListing($this->tournaments, $this->announcer);
		$this->drafts = new DraftCards(
			$this->telegram,
			$this->tournaments,
			$this->players,
			$this->balance_repo,
			$this->conversations,
			$this->announcer,
			$this->access,
			$clock,
			$this->logger,
			(int) ($config['conversation_ttl_minutes'] ?? 30),
		);
		$this->create_access = new CreateAccess(
			$this->telegram,
			$this->chats,
			$this->players,
			$this->access,
			$clock,
			$this->logger,
			$owner_tg_id,
			(bool) ($config['tournament']['anyone_can_create'] ?? false),
		);
		$this->private_groups = new PrivateGroups(
			$this->chats,
			$this->players,
			$this->access,
			$this->admin_sync,
			$this->telegram,
			$this->logger,
			(bool) ($config['tournament']['anyone_can_create'] ?? false),
			$this->create_access,
		);
		$this->random = $config['random'] ?? new SystemRandom();
		$this->disband_notifier = new TeamDisbandNotifier(
			$this->telegram,
			$this->bot,
			$this->players,
			$this->logger,
		);
		$this->group_redirect = new GroupRedirect($this->bot, $this->tournaments, $this->tournament_resolver);
		$commands = new AdminCommands(
			$this->telegram,
			$this->bot,
			$this->tournaments,
			$this->balances,
			$this->balance_repo,
			$this->players,
			$this->tournament_resolver,
			$this->player_resolver,
			$this->announcer,
			$clock,
			$this->drafts,
			$this->listing,
			$this->private_groups,
			$this->create_access,
		);

		$this->dispatcher = new Dispatcher(
			$this->tournaments,
			$this->teams,
			$this->balances,
			$this->balance_repo,
			$this->players,
			$this->tournament_resolver,
			$this->player_resolver,
			$this->announcer,
			$clock,
			$this->logger,
			$this->drafts,
			$this->bot->username,
			$this->private_groups,
			$this->create_access,
			$this->disband_notifier,
		);
		$ai = $this->makeAiClient();
		if ($ai !== null) {
			$this->assistant = new Assistant(
				$ai,
				$this->dispatcher,
				$this->conversations,
				$this->bot,
				$clock,
				$this->logger,
				$this->systemPrompt(),
				(int) ($config['conversation_ttl_minutes'] ?? 30),
			);
		}

		$messages = new MessageHandler(
			$this->telegram,
			$this->bot,
			$this->assistant,
			$this->logger,
			$this->tournaments,
			$this->drafts,
			$this->listing,
			$this->private_groups,
		);
		$views = new PrivateViews(
			$this->telegram,
			$this->tournaments,
			$this->teams,
			$this->announcer,
			$this->listing,
			$this->private_groups,
			$this->access,
		);
		$this->group_chat = new GroupChat(
			$this->telegram,
			$this->bot,
			$this->tournaments,
			$this->teams,
			$this->tournament_resolver,
			$this->group_redirect,
			$commands,
			$this->random,
			$this->logger,
			$this->announcer,
		);
		$this->router = new UpdateRouter(

			$this->telegram,
			$this->bot,
			$this->chats,
			$this->players,
			$this->admin_sync,
			$this->access,
			$clock,
			$commands,
			$messages,
			new CallbackHandler(
				$this->telegram,
				$this->bot,
				$this->tournaments,
				$this->teams,
				$this->announcer,
				$this->drafts,
				$this->private_groups,
				$views,
				$this->conversations,
				$clock,
				(int) ($config['conversation_ttl_minutes'] ?? 30),
				$this->disband_notifier,
			),
			new EditedReplyRepository($db),
			$this->logger,
			new PrivateChatHandler(
				$this->telegram,
				$this->bot,
				$this->access,
				$this->admin_sync,
				$this->players,
				$this->tournaments,
				$this->private_groups,
				$views,
				$this->drafts,
				$commands,
				$messages,
				$clock,
				$this->create_access,
			),
			$this->group_redirect,
			$this->dialogs,
			$this->group_chat,
		);
	}

	public function cron(): CronRunner {
		$cron = $this->config['cron'] ?? [];
		return new CronRunner(
			$this->tournaments,
			$this->conversations,
			$this->players,
			$this->announcer,
			$this->telegram,
			$this->clock,
			$this->logger,
			array_map('intval', (array) ($cron['reminder_minutes'] ?? [60, 15])),
			(int) ($cron['finish_after_hours'] ?? 2),
			$this->chats,
			new GroupNoticeRepository($this->db),
			(bool) ($cron['remind_all_groups'] ?? true),
			$this->bot->username,
		);
	}

	/**
		null when OPENAI_API_KEY is empty: the bot then answers mentions with a short help.
	*/
	protected function makeAiClient(): ?AiClient {
		$openai = $this->config['openai'] ?? [];
		$key = (string) ($openai['api_key'] ?? '');
		if ($key === '') {
			return null;
		}
		return new OpenAiClient(
			$this->http,
			$key,
			(string) ($openai['model'] ?? 'gpt-6-luna'),
			(string) ($openai['reasoning_effort'] ?? 'low'),
			(string) ($openai['base_url'] ?? 'https://api.openai.com/v1'),
			(int) ($openai['timeout'] ?? 30),
			$this->logger,
		);
	}

	/**
		storage/logs/dialogs, or null (off) when DIALOG_LOG=0 or there is no logs path (tests).
	*/
	protected function dialogDir(): ?string {
		$logs = (string) ($this->config['paths']['logs'] ?? '');
		if ($logs === '' || !($this->config['dialog_log'] ?? true)) {
			return null;
		}
		return (string) ($this->config['paths']['dialogs'] ?? $logs . '/dialogs');
	}

	protected function systemPrompt(): string {
		$path = (string) ($this->config['paths']['prompt'] ?? dirname(__DIR__) . '/config/prompts/system.md');
		$prompt = is_file($path) ? (string) file_get_contents($path) : '';
		$prompt = str_replace('{bot_username}', $this->bot->username, $prompt);
		return self::withPersonaName($prompt, (string) ($this->config['persona_name'] ?? ''));
	}

	/**
		BOT_PERSONA_NAME: when set, the prompt tells the model her name (after the first line).
	*/
	public static function withPersonaName(string $prompt, string $name): string {
		$name = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $name)), 0, 50);
		if ($name === '') {
			return $prompt;
		}
		$line = sprintf('Тебя зовут %s. Если спрашивают, как тебя зовут, представляйся так.', $name);
		$parts = explode("\n", $prompt, 2);
		return $parts[0] . "\n" . $line . (isset($parts[1]) ? "\n" . $parts[1] : '');
	}

	/**
		@param array<string, mixed> $config
	*/
	public static function fromConfig(array $config, ?Logger $logger = null): self {
		return new self(
			Database::fromConfig($config['db']),
			new Support\SystemClock(),
			$config,
			null,
			$logger ?? Logger::fromConfig($config),
		);
	}

	/**
		The owner always has a player row and the default balance scale exists.
	*/
	public function ensureOwner(): void {
		if ($this->bot->owner_tg_id === 0) {
			return;
		}
		$owner = $this->players->ensureTelegramId($this->bot->owner_tg_id, 'Владелец бота', $this->clock->now());
		$this->balances->ensureDefault($owner->id);
	}
}
