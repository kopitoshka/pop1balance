<?php

declare(strict_types=1);

namespace P1Bot;

use P1Bot\Ai\AiClient;
use P1Bot\Ai\Assistant;
use P1Bot\Ai\Dispatcher;
use P1Bot\Ai\OpenAiClient;
use P1Bot\App\Announcer;
use P1Bot\App\CronRunner;
use P1Bot\App\DraftCards;
use P1Bot\App\PlayerResolver;
use P1Bot\App\TournamentListing;
use P1Bot\App\TournamentResolver;
use P1Bot\Domain\AccessPolicy;
use P1Bot\Domain\BalanceService;
use P1Bot\Domain\TeamService;
use P1Bot\Domain\TournamentService;
use P1Bot\Handlers\AdminCommands;
use P1Bot\Handlers\CallbackHandler;
use P1Bot\Handlers\MessageHandler;
use P1Bot\Handlers\UpdateRouter;
use P1Bot\Http\CurlHttpClient;
use P1Bot\Http\HttpClient;
use P1Bot\Repository\AdminRepository;
use P1Bot\Repository\BalanceRepository;
use P1Bot\Repository\ChatRepository;
use P1Bot\Repository\EditedReplyRepository;
use P1Bot\Repository\ConversationRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Repository\SoloPlayerRepository;
use P1Bot\Repository\TeamRepository;
use P1Bot\Repository\TournamentRepository;
use P1Bot\Support\Clock;
use P1Bot\Support\Database;
use P1Bot\Support\Logger;
use P1Bot\Telegram\BotApi;
use P1Bot\Telegram\BotIdentity;
use P1Bot\Telegram\ChatAdminSync;
use P1Bot\Telegram\TelegramApi;

/** Hand-wired service container (no framework). */
final class Bootstrap
{
    public PlayerRepository $players;
    public ChatRepository $chats;
    public AdminRepository $admins;
    public BalanceRepository $balanceRepo;
    public TournamentRepository $tournamentRepo;
    public TeamRepository $teamRepo;
    public SoloPlayerRepository $soloRepo;
    public ConversationRepository $conversations;

    public AccessPolicy $access;
    public BalanceService $balances;
    public TournamentService $tournaments;
    public TeamService $teams;

    public Logger $logger;
    public HttpClient $http;
    public BotIdentity $bot;
    public TelegramApi $telegram;
    public ChatAdminSync $adminSync;
    public UpdateRouter $router;
    public Announcer $announcer;
    public DraftCards $drafts;
    public TournamentListing $listing;
    public PlayerResolver $playerResolver;
    public TournamentResolver $tournamentResolver;
    public Dispatcher $dispatcher;
    public ?Assistant $assistant = null;

    /** @var array<string, mixed> */
    public array $config;

    /**
     * @param array<string, mixed> $config see config/config.php; missing keys get defaults
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
        $this->balanceRepo = new BalanceRepository($db);
        $this->tournamentRepo = new TournamentRepository($db);
        $this->teamRepo = new TeamRepository($db);
        $this->soloRepo = new SoloPlayerRepository($db);
        $this->conversations = new ConversationRepository($db);

        $ownerTgId = (int) ($config['owner_tg_id'] ?? 0);
        $this->access = new AccessPolicy(
            $this->admins,
            $this->players,
            $ownerTgId,
            (bool) ($config['tournament']['anyone_can_create'] ?? false),
        );
        $this->balances = new BalanceService($this->balanceRepo, $this->players, $this->access, $db, $clock);
        $this->tournaments = new TournamentService(
            $this->tournamentRepo,
            $this->teamRepo,
            $this->soloRepo,
            $this->balanceRepo,
            $this->access,
            $db,
            $clock,
            (int) ($config['tournament']['default_max_players'] ?? 24),
        );
        $this->teams = new TeamService(
            $this->teamRepo,
            $this->soloRepo,
            $this->tournamentRepo,
            $this->players,
            $this->balances,
            $this->access,
            $db,
            $clock,
        );

        $token = (string) ($config['telegram']['token'] ?? '');
        $this->bot = new BotIdentity(BotApi::botIdFromToken($token), (string) ($config['telegram']['username'] ?? ''), $ownerTgId);
        $this->telegram = new BotApi($this->http, $token, 15, $this->logger);
        $this->adminSync = new ChatAdminSync(
            $this->telegram,
            $this->chats,
            $this->admins,
            $this->players,
            $clock,
            $this->bot,
            $this->logger,
            (int) ($config['admins_cache_minutes'] ?? 10),
        );

        $this->playerResolver = new PlayerResolver($this->players, $clock);
        $this->tournamentResolver = new TournamentResolver($this->tournamentRepo);
        $this->announcer = new Announcer($this->telegram, $this->tournaments, $this->players, $this->bot, $this->logger, $this->tournamentResolver);
        $this->listing = new TournamentListing($this->tournaments, $this->announcer);
        $this->drafts = new DraftCards(
            $this->telegram,
            $this->tournaments,
            $this->players,
            $this->balanceRepo,
            $this->conversations,
            $this->announcer,
            $this->access,
            $clock,
            $this->logger,
            (int) ($config['conversation_ttl_minutes'] ?? 30),
        );
        $commands = new AdminCommands(
            $this->telegram,
            $this->bot,
            $this->tournaments,
            $this->balances,
            $this->balanceRepo,
            $this->players,
            $this->tournamentResolver,
            $this->playerResolver,
            $this->announcer,
            $clock,
            $this->drafts,
            $this->listing,
        );

        $this->dispatcher = new Dispatcher(
            $this->tournaments,
            $this->teams,
            $this->balances,
            $this->balanceRepo,
            $this->players,
            $this->tournamentResolver,
            $this->playerResolver,
            $this->announcer,
            $clock,
            $this->logger,
            $this->drafts,
            $this->bot->username,
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

        $this->router = new UpdateRouter(
            $this->telegram,
            $this->bot,
            $this->chats,
            $this->players,
            $this->adminSync,
            $this->access,
            $clock,
            $commands,
            new MessageHandler($this->telegram, $this->bot, $this->assistant, $this->logger, $this->tournaments, $this->drafts, $this->listing),
            new CallbackHandler($this->telegram, $this->bot, $this->tournaments, $this->teams, $this->announcer, $this->drafts),
            new EditedReplyRepository($db),
            $this->logger,
        );
    }

    public function cron(): CronRunner
    {
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
            (int) ($cron['finish_after_hours'] ?? 4),
        );
    }

    /** null when OPENAI_API_KEY is empty: the bot then answers mentions with a short help. */
    private function makeAiClient(): ?AiClient
    {
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

    private function systemPrompt(): string
    {
        $path = (string) ($this->config['paths']['prompt'] ?? dirname(__DIR__) . '/config/prompts/system.md');
        $prompt = is_file($path) ? (string) file_get_contents($path) : '';
        return str_replace('{bot_username}', $this->bot->username, $prompt);
    }

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config, ?Logger $logger = null): self
    {
        return new self(
            Database::fromConfig($config['db']),
            new Support\SystemClock(),
            $config,
            null,
            $logger ?? Logger::fromConfig($config),
        );
    }

    /** The owner always has a player row and the default balance scale exists. */
    public function ensureOwner(): void
    {
        if ($this->bot->ownerTgId === 0) {
            return;
        }
        $owner = $this->players->ensureTelegramId($this->bot->ownerTgId, 'Владелец бота', $this->clock->now());
        $this->balances->ensureDefault($owner->id);
    }
}
