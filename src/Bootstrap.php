<?php

declare(strict_types=1);

namespace P1Bot;

use P1Bot\Domain\AccessPolicy;
use P1Bot\Domain\BalanceService;
use P1Bot\Domain\TeamService;
use P1Bot\Domain\TournamentService;
use P1Bot\Repository\AdminRepository;
use P1Bot\Repository\BalanceRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Repository\SoloPlayerRepository;
use P1Bot\Repository\TeamRepository;
use P1Bot\Repository\TournamentRepository;
use P1Bot\Support\Clock;
use P1Bot\Support\Database;

/** Hand-wired service container (no framework). */
final class Bootstrap
{
    public PlayerRepository $players;
    public AccessPolicy $access;
    public BalanceService $balances;
    public TournamentService $tournaments;
    public TeamService $teams;

    /**
     * @param array{owner_tg_id: int, tournament: array{default_max_players: int, anyone_can_create: bool}} $config
     */
    public function __construct(public Database $db, Clock $clock, array $config)
    {
        $this->players = new PlayerRepository($db);
        $balanceRepo = new BalanceRepository($db);
        $tournamentRepo = new TournamentRepository($db);
        $teamRepo = new TeamRepository($db);
        $soloRepo = new SoloPlayerRepository($db);

        $this->access = new AccessPolicy(
            new AdminRepository($db),
            $this->players,
            (int) $config['owner_tg_id'],
            (bool) $config['tournament']['anyone_can_create'],
        );
        $this->balances = new BalanceService($balanceRepo, $this->players, $this->access, $db, $clock);
        $this->tournaments = new TournamentService(
            $tournamentRepo,
            $teamRepo,
            $soloRepo,
            $balanceRepo,
            $this->access,
            $db,
            $clock,
            (int) $config['tournament']['default_max_players'],
        );
        $this->teams = new TeamService($teamRepo, $soloRepo, $tournamentRepo, $this->players, $this->balances, $this->access, $db, $clock);
    }
}
