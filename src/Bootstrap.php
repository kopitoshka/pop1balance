<?php

declare(strict_types=1);

namespace P1Bot;

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
        $this->telegram = new BotApi($this->http, $token);
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

        $this->router = new UpdateRouter(
            $this->telegram,
            $this->bot,
            $this->chats,
            $this->players,
            $this->adminSync,
            $this->access,
            $clock,
            new AdminCommands($this->telegram, $this->bot),
            new MessageHandler($this->telegram, $this->bot),
            new CallbackHandler($this->telegram),
        );
    }

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config): self
    {
        return new self(
            Database::fromConfig($config['db']),
            new Support\SystemClock(),
            $config,
            null,
            new Logger((string) $config['paths']['logs']),
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
