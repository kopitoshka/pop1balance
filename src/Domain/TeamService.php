<?php

declare(strict_types=1);

namespace P1Bot\Domain;

use P1Bot\Domain\Exception\LevelLimitExceeded;
use P1Bot\Domain\Exception\NotFound;
use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Model\Team;
use P1Bot\Domain\Model\TeamEntry;
use P1Bot\Domain\Model\TeamMember;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Repository\SoloPlayerRepository;
use P1Bot\Repository\TeamRepository;
use P1Bot\Repository\TournamentRepository;
use P1Bot\Support\Clock;
use P1Bot\Support\Database;

/**
 * Team and solo registration. A player is in at most one entry per tournament:
 * one team OR the solo list. Adding a solo player to a team drops their solo entry.
 *
 * A team becomes 'registered' (and gets registered_at = queue position) when its roster
 * reaches team_size; dropping below team_size returns it to 'forming' and clears registered_at.
 */
final class TeamService
{
    public const MAX_NAME_LENGTH = 64;

    public function __construct(
        private TeamRepository $teams,
        private SoloPlayerRepository $solos,
        private TournamentRepository $tournaments,
        private PlayerRepository $players,
        private BalanceService $balance,
        private AccessPolicy $access,
        private Database $db,
        private Clock $clock,
    ) {
    }

    public function get(int $teamId): TeamEntry
    {
        $team = $this->teams->find($teamId) ?? throw new NotFound('Такой команды нет.');
        return new TeamEntry($team, $this->teams->members($team->id));
    }

    public function findByName(int $tournamentId, string $name): ?TeamEntry
    {
        $team = $this->teams->findByName($tournamentId, $name);
        return $team === null ? null : new TeamEntry($team, $this->teams->members($team->id));
    }

    /** Team the player is in, if any. */
    public function teamOf(int $tournamentId, int $playerId): ?TeamEntry
    {
        $membership = $this->teams->findMembership($tournamentId, $playerId);
        return $membership === null ? null : $this->get($membership->teamId);
    }

    /**
     * Creates a team; the author becomes captain and its first member.
     *
     * @param list<int> $playerIds other members (captain may be omitted)
     */
    public function createTeam(int $tournamentId, int $captainId, string $name, array $playerIds = []): TeamEntry
    {
        return $this->db->transactional(function () use ($tournamentId, $captainId, $name, $playerIds): TeamEntry {
            $tournament = $this->openTournament($tournamentId);
            $name = $this->validName($tournamentId, $name, null);

            $roster = array_values(array_unique(array_merge([$captainId], $playerIds)));
            if (count($roster) > $tournament->teamSize) {
                throw new ValidationError(sprintf('В команде этого турнира максимум %d игрока(ов), а указано %d.', $tournament->teamSize, count($roster)));
            }

            $levels = [];
            foreach ($roster as $playerId) {
                $this->assertFreeForTeam($tournament, $playerId, null);
                $levels[$playerId] = $this->balance->levelOf($tournament->balanceId, $playerId);
            }
            $this->assertWithinLimit($tournament, array_sum($levels));

            $team = $this->teams->create($tournament->id, $captainId, $name, $this->clock->now());
            foreach ($levels as $playerId => $level) {
                $this->solos->remove($tournament->id, $playerId);
                $this->teams->addMember($team, $playerId, $level, $this->clock->now());
            }
            $this->syncStatus($team, $tournament);
            return $this->get($team->id);
        });
    }

    public function addPlayer(int $actorId, int $teamId, int $playerId): TeamEntry
    {
        return $this->db->transactional(function () use ($actorId, $teamId, $playerId): TeamEntry {
            [$team, $tournament] = $this->captainContext($actorId, $teamId, true);
            $members = $this->teams->members($team->id);
            if (count($members) >= $tournament->teamSize) {
                throw new ValidationError(sprintf('В команде «%s» уже %d из %d игроков, мест нет.', $team->name, count($members), $tournament->teamSize));
            }
            $this->assertFreeForTeam($tournament, $playerId, $team->id);
            $level = $this->balance->levelOf($tournament->balanceId, $playerId);
            $this->assertWithinLimit($tournament, self::points($members) + $level);

            $this->solos->remove($tournament->id, $playerId);
            $this->teams->addMember($team, $playerId, $level, $this->clock->now());
            $this->syncStatus($team, $tournament);
            return $this->get($team->id);
        });
    }

    /** Captain removes a member. A registered team that is no longer full leaves the queue. */
    public function removePlayer(int $actorId, int $teamId, int $playerId): TeamEntry
    {
        return $this->db->transactional(function () use ($actorId, $teamId, $playerId): TeamEntry {
            [$team, $tournament] = $this->captainContext($actorId, $teamId, false);
            if ($playerId === $team->captainId) {
                throw new ValidationError('Капитана нельзя убрать из команды. Передай капитанство или распусти команду.');
            }
            $this->assertMember($team, $playerId);
            $this->teams->removeMember($team->id, $playerId);
            $this->syncStatus($team, $tournament);
            return $this->get($team->id);
        });
    }

    /** Swaps one member for another in one step, keeping the team's place in the queue. */
    public function replacePlayer(int $actorId, int $teamId, int $outPlayerId, int $inPlayerId): TeamEntry
    {
        return $this->db->transactional(function () use ($actorId, $teamId, $outPlayerId, $inPlayerId): TeamEntry {
            [$team, $tournament] = $this->captainContext($actorId, $teamId, true);
            if ($outPlayerId === $team->captainId) {
                throw new ValidationError('Капитана нельзя заменить. Сначала передай капитанство.');
            }
            $this->assertMember($team, $outPlayerId);
            $this->assertFreeForTeam($tournament, $inPlayerId, $team->id);

            $members = array_filter($this->teams->members($team->id), static fn (TeamMember $m): bool => $m->playerId !== $outPlayerId);
            $level = $this->balance->levelOf($tournament->balanceId, $inPlayerId);
            $this->assertWithinLimit($tournament, self::points(array_values($members)) + $level);

            $this->teams->removeMember($team->id, $outPlayerId);
            $this->solos->remove($tournament->id, $inPlayerId);
            $this->teams->addMember($team, $inPlayerId, $level, $this->clock->now());
            return $this->get($team->id);
        });
    }

    /** A non-captain member leaves the team on their own. */
    public function leaveTeam(int $playerId, int $teamId): TeamEntry
    {
        return $this->db->transactional(function () use ($playerId, $teamId): TeamEntry {
            $team = $this->teams->find($teamId) ?? throw new NotFound('Такой команды нет.');
            $tournament = $this->changeableTournament($team->tournamentId);
            if ($playerId === $team->captainId) {
                throw new ValidationError('Капитан не может просто выйти: передай капитанство или распусти команду.');
            }
            $this->assertMember($team, $playerId);
            $this->teams->removeMember($team->id, $playerId);
            $this->syncStatus($team, $tournament);
            return $this->get($team->id);
        });
    }

    public function renameTeam(int $actorId, int $teamId, string $name): TeamEntry
    {
        return $this->db->transactional(function () use ($actorId, $teamId, $name): TeamEntry {
            [$team] = $this->captainContext($actorId, $teamId, false);
            $this->teams->rename($team->id, $this->validName($team->tournamentId, $name, $team->id));
            return $this->get($team->id);
        });
    }

    public function transferCaptain(int $actorId, int $teamId, int $newCaptainId): TeamEntry
    {
        return $this->db->transactional(function () use ($actorId, $teamId, $newCaptainId): TeamEntry {
            [$team] = $this->captainContext($actorId, $teamId, false);
            if ($newCaptainId === $team->captainId) {
                throw new ValidationError('Этот игрок уже капитан.');
            }
            $this->assertMember($team, $newCaptainId);
            $this->teams->setCaptain($team->id, $newCaptainId);
            return $this->get($team->id);
        });
    }

    /** Disbands the team (row deleted, members cascade). Captain, organizer or chat admin. */
    public function withdrawTeam(int $actorId, int $teamId): void
    {
        $this->db->transactional(function () use ($actorId, $teamId): void {
            $team = $this->teams->find($teamId) ?? throw new NotFound('Такой команды нет.');
            $tournament = $this->changeableTournament($team->tournamentId);
            if ($team->captainId !== $actorId && !$this->access->canManageTournament($tournament, $actorId)) {
                throw new PermissionDenied(sprintf('Распустить команду «%s» может только её капитан или организатор.', $team->name));
            }
            $this->teams->delete($team->id);
        });
    }

    /** "Хочу участвовать" without a team. */
    public function joinSolo(int $tournamentId, int $playerId): void
    {
        $this->db->transactional(function () use ($tournamentId, $playerId): void {
            $tournament = $this->openTournament($tournamentId);
            $team = $this->teamOf($tournament->id, $playerId);
            if ($team !== null) {
                throw new ValidationError(sprintf('Ты уже в команде «%s». Чтобы записаться соло, сначала выйди из неё.', $team->team->name));
            }
            if ($this->solos->find($tournament->id, $playerId) !== null) {
                throw new ValidationError('Ты уже записан соло на этот турнир.');
            }
            $level = $this->balance->levelOf($tournament->balanceId, $playerId);
            $this->solos->add($tournament->id, $playerId, $level, $this->clock->now());
        });
    }

    public function leaveSolo(int $tournamentId, int $playerId): void
    {
        $this->changeableTournament($tournamentId);
        if (!$this->solos->remove($tournamentId, $playerId)) {
            throw new NotFound('Ты не записан соло на этот турнир.');
        }
    }

    /** @return array{0: Team, 1: Tournament} */
    private function captainContext(int $actorId, int $teamId, bool $needsOpenRegistration): array
    {
        $team = $this->teams->find($teamId) ?? throw new NotFound('Такой команды нет.');
        $this->access->assertCaptain($team, $actorId);
        $tournament = $needsOpenRegistration
            ? $this->openTournament($team->tournamentId)
            : $this->changeableTournament($team->tournamentId);
        return [$team, $tournament];
    }

    /** Registration must be open to join or grow a roster. */
    private function openTournament(int $tournamentId): Tournament
    {
        $tournament = $this->tournaments->find($tournamentId) ?? throw new NotFound('Такого турнира нет.');
        if (!$tournament->isRegistrationOpen()) {
            throw new ValidationError(sprintf('Регистрация на турнир «%s» сейчас не идёт (%s).', $tournament->title, TournamentStatus::label($tournament->status)));
        }
        return $tournament;
    }

    /** Leaving, renaming and withdrawing are allowed until the tournament starts. */
    private function changeableTournament(int $tournamentId): Tournament
    {
        $tournament = $this->tournaments->find($tournamentId) ?? throw new NotFound('Такого турнира нет.');
        if (!in_array($tournament->status, [TournamentStatus::REGISTRATION, TournamentStatus::CLOSED], true)) {
            throw new ValidationError(sprintf('Турнир «%s» %s, заявки уже не меняются.', $tournament->title, TournamentStatus::label($tournament->status)));
        }
        return $tournament;
    }

    private function assertFreeForTeam(Tournament $tournament, int $playerId, ?int $ownTeamId): void
    {
        $player = $this->players->find($playerId) ?? throw new NotFound('Такого игрока я не знаю.');
        $membership = $this->teams->findMembership($tournament->id, $playerId);
        if ($membership === null) {
            return;
        }
        if ($membership->teamId === $ownTeamId) {
            throw new ValidationError(sprintf('%s уже в этой команде.', $player->mention()));
        }
        $other = $this->teams->find($membership->teamId);
        throw new ValidationError(sprintf('%s уже играет в команде «%s» на этом турнире.', $player->mention(), $other !== null ? $other->name : '?'));
    }

    private function assertMember(Team $team, int $playerId): void
    {
        $membership = $this->teams->findMembership($team->tournamentId, $playerId);
        if ($membership === null || $membership->teamId !== $team->id) {
            throw new NotFound(sprintf('Этого игрока нет в команде «%s».', $team->name));
        }
    }

    private function assertWithinLimit(Tournament $tournament, int $points): void
    {
        if ($tournament->hasLevelLimit() && $points > (int) $tournament->maxPoints) {
            throw new LevelLimitExceeded($points, (int) $tournament->maxPoints);
        }
    }

    private function validName(int $tournamentId, string $name, ?int $exceptTeamId): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new ValidationError(sprintf('Название команды должно быть от 1 до %d символов.', self::MAX_NAME_LENGTH));
        }
        if ($this->teams->findByName($tournamentId, $name, $exceptTeamId) !== null) {
            throw new ValidationError(sprintf('Команда «%s» на этом турнире уже есть, придумай другое название.', $name));
        }
        return $name;
    }

    /** Full roster => registered (keeps an existing registered_at); otherwise forming. */
    private function syncStatus(Team $team, Tournament $tournament): void
    {
        $fresh = $this->teams->find($team->id) ?? throw new NotFound('Такой команды нет.');
        $full = $this->teams->countMembers($team->id) >= $tournament->teamSize;
        if ($full && !$fresh->isRegistered()) {
            $this->teams->setStatus($team->id, TeamStatus::REGISTERED, $this->clock->now());
        } elseif (!$full && $fresh->isRegistered()) {
            $this->teams->setStatus($team->id, TeamStatus::FORMING, null);
        }
    }

    /** @param list<TeamMember> $members */
    private static function points(array $members): int
    {
        return array_sum(array_map(static fn (TeamMember $m): int => $m->levelSnapshot, $members));
    }
}
