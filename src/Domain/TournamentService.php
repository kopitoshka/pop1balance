<?php

declare(strict_types=1);

namespace P1Bot\Domain;

use DateTimeImmutable;
use P1Bot\Domain\Exception\NotFound;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Model\TeamEntry;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Repository\BalanceRepository;
use P1Bot\Repository\SoloPlayerRepository;
use P1Bot\Repository\TeamRepository;
use P1Bot\Repository\TournamentRepository;
use P1Bot\Support\Clock;
use P1Bot\Support\Database;

final class TournamentService
{
    public const MIN_TEAM_SIZE = 2;
    public const MAX_TEAM_SIZE = 4;

    public function __construct(
        private TournamentRepository $tournaments,
        private TeamRepository $teams,
        private SoloPlayerRepository $solos,
        private BalanceRepository $balances,
        private AccessPolicy $access,
        private Database $db,
        private Clock $clock,
        private int $defaultMaxPlayers = 24,
    ) {
    }

    public function get(int $tournamentId): Tournament
    {
        return $this->tournaments->find($tournamentId) ?? throw new NotFound('Такого турнира нет.');
    }

    /** Creates a draft tournament; the author becomes the organizer. */
    public function create(CreateTournament $input): Tournament
    {
        $this->access->assertCanCreateTournament($input->chatId, $input->organizerId);

        $title = $this->validTitle($input->title);
        $startsAt = $this->validStart($input->startsAtMsk);
        $teamSize = $this->validTeamSize($input->teamSize);
        [$balanceId, $reference, $maxPoints] = $this->levelLimit($input->referenceTeam, $input->balanceId, $teamSize);
        $maxPlayers = $this->validMaxPlayers($input->maxPlayers ?? $this->defaultMaxPlayers, $teamSize);

        return $this->tournaments->create([
            'chat_id' => $input->chatId,
            'organizer_id' => $input->organizerId,
            'title' => $title,
            'starts_at' => $startsAt,
            'team_size' => $teamSize,
            'balance_id' => $balanceId,
            'reference_team' => $reference,
            'max_points' => $maxPoints,
            'max_players' => $maxPlayers,
            'status' => TournamentStatus::DRAFT,
        ], $this->clock->now());
    }

    public function edit(int $actorId, int $tournamentId, EditTournament $edit): Tournament
    {
        return $this->db->transactional(function () use ($actorId, $tournamentId, $edit): Tournament {
            $t = $this->get($tournamentId);
            $this->access->assertCanManageTournament($t, $actorId);
            $this->assertEditable($t);

            $fields = [];
            if ($edit->title !== null) {
                $fields['title'] = $this->validTitle($edit->title);
            }
            if ($edit->startsAtMsk !== null) {
                $fields['starts_at'] = $this->validStart($edit->startsAtMsk);
                $fields['reminded_at'] = null;
            }

            $limitTouched = $edit->teamSize !== null || $edit->referenceTeam !== null
                || $edit->balanceId !== null || $edit->removeLevelLimit;
            if ($limitTouched) {
                if ($this->hasEntries($t->id)) {
                    throw new ValidationError('Размер команды и лимит по уровню нельзя менять, когда уже есть заявки.');
                }
                $teamSize = $this->validTeamSize($edit->teamSize ?? $t->teamSize);
                if ($edit->removeLevelLimit) {
                    [$balanceId, $reference, $maxPoints] = [null, null, null];
                } else {
                    $reference = $edit->referenceTeam ?? $t->referenceTeam;
                    [$balanceId, $reference, $maxPoints] = $this->levelLimit($reference, $edit->balanceId ?? $t->balanceId, $teamSize);
                }
                $fields += ['team_size' => $teamSize, 'balance_id' => $balanceId, 'reference_team' => $reference, 'max_points' => $maxPoints];
            }

            if ($edit->maxPlayers !== null || isset($fields['team_size'])) {
                $fields['max_players'] = $this->validMaxPlayers($edit->maxPlayers ?? $t->maxPlayers, $fields['team_size'] ?? $t->teamSize);
            }

            $this->tournaments->update($t->id, $fields);
            return $this->get($t->id);
        });
    }

    /** Lowering the limit is allowed: whoever no longer fits simply becomes reserve. */
    public function setMaxPlayers(int $actorId, int $tournamentId, int $maxPlayers): Tournament
    {
        return $this->edit($actorId, $tournamentId, new EditTournament(maxPlayers: $maxPlayers));
    }

    public function openRegistration(int $actorId, int $tournamentId): Tournament
    {
        return $this->transition($actorId, $tournamentId, [TournamentStatus::DRAFT, TournamentStatus::CLOSED], TournamentStatus::REGISTRATION);
    }

    public function closeRegistration(int $actorId, int $tournamentId): Tournament
    {
        return $this->transition($actorId, $tournamentId, [TournamentStatus::REGISTRATION], TournamentStatus::CLOSED);
    }

    public function start(int $actorId, int $tournamentId): Tournament
    {
        return $this->transition($actorId, $tournamentId, [TournamentStatus::REGISTRATION, TournamentStatus::CLOSED], TournamentStatus::RUNNING);
    }

    public function finish(int $actorId, int $tournamentId): Tournament
    {
        return $this->transition($actorId, $tournamentId, [TournamentStatus::RUNNING], TournamentStatus::FINISHED);
    }

    public function cancel(int $actorId, int $tournamentId): Tournament
    {
        return $this->transition(
            $actorId,
            $tournamentId,
            [TournamentStatus::DRAFT, TournamentStatus::REGISTRATION, TournamentStatus::CLOSED, TournamentStatus::RUNNING],
            TournamentStatus::CANCELLED,
        );
    }

    public function setAnnounceMessage(int $tournamentId, int $messageId): void
    {
        $this->tournaments->update($tournamentId, ['announce_msg_id' => $messageId]);
    }

    public function markReminded(int $tournamentId): void
    {
        $this->tournaments->update($tournamentId, ['reminded_at' => $this->clock->now()]);
    }

    /** @return list<Tournament> tournaments to remind about: start within $minutesAhead, not reminded yet */
    public function dueForReminder(int $minutesAhead): array
    {
        $now = $this->clock->now();
        return $this->tournaments->listDueForReminder($now, $now->modify("+{$minutesAhead} minutes"));
    }

    /** @return list<Tournament> registration / closed / running, soonest first */
    public function listActive(int $chatId): array
    {
        return $this->tournaments->listByChat($chatId, TournamentStatus::active());
    }

    public function lineup(int $tournamentId): Lineup
    {
        $t = $this->get($tournamentId);
        $registered = array_map(
            fn ($team): TeamEntry => new TeamEntry($team, $this->teams->members($team->id)),
            $this->teams->listRegistered($t->id),
        );
        $forming = [];
        foreach ($this->teams->listByTournament($t->id) as $team) {
            if (!$team->isRegistered()) {
                $forming[] = new TeamEntry($team, $this->teams->members($team->id));
            }
        }
        return Lineup::compute($t->maxPlayers, $registered, $this->solos->listByTournament($t->id), $forming);
    }

    /**
     * @param list<string> $from
     */
    private function transition(int $actorId, int $tournamentId, array $from, string $to): Tournament
    {
        $t = $this->get($tournamentId);
        $this->access->assertCanManageTournament($t, $actorId);
        if (!in_array($t->status, $from, true)) {
            throw new ValidationError(sprintf('Турнир «%s» сейчас в статусе «%s», это действие недоступно.', $t->title, TournamentStatus::label($t->status)));
        }
        $this->tournaments->update($t->id, ['status' => $to]);
        return $this->get($t->id);
    }

    private function assertEditable(Tournament $t): void
    {
        if (in_array($t->status, [TournamentStatus::FINISHED, TournamentStatus::CANCELLED], true)) {
            throw new ValidationError(sprintf('Турнир «%s» %s, менять его нельзя.', $t->title, TournamentStatus::label($t->status)));
        }
    }

    private function hasEntries(int $tournamentId): bool
    {
        return $this->teams->listByTournament($tournamentId) !== [] || $this->solos->listByTournament($tournamentId) !== [];
    }

    private function validTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 255) {
            throw new ValidationError('Название турнира должно быть от 1 до 255 символов.');
        }
        return $title;
    }

    private function validStart(string $startsAtMsk): DateTimeImmutable
    {
        $startsAt = MoscowTime::parse($startsAtMsk);
        if ($startsAt <= $this->clock->now()) {
            throw new ValidationError('Время турнира уже прошло. Укажи будущее время по МСК.');
        }
        return $startsAt;
    }

    private function validTeamSize(int $teamSize): int
    {
        if ($teamSize < self::MIN_TEAM_SIZE || $teamSize > self::MAX_TEAM_SIZE) {
            throw new ValidationError(sprintf('В команде может быть от %d до %d игроков.', self::MIN_TEAM_SIZE, self::MAX_TEAM_SIZE));
        }
        return $teamSize;
    }

    private function validMaxPlayers(int $maxPlayers, int $teamSize): int
    {
        if ($maxPlayers < $teamSize || $maxPlayers > 1000) {
            throw new ValidationError(sprintf('Лимит участников должен быть от %d до 1000.', $teamSize));
        }
        return $maxPlayers;
    }

    /**
     * Reference team => [balance_id, canonical reference, max_points]. No reference => no limit.
     * A reference without an explicit scale uses the default scale.
     *
     * @return array{0: ?int, 1: ?string, 2: ?int}
     */
    private function levelLimit(?string $referenceTeam, ?int $balanceId, int $teamSize): array
    {
        if ($referenceTeam === null || trim($referenceTeam) === '') {
            if ($balanceId !== null) {
                throw new ValidationError('Для баланса нужна эталонная команда, например 3,3,4.');
            }
            return [null, null, null];
        }
        $levels = Levels::parseReferenceTeam($referenceTeam);
        if (count($levels) !== $teamSize) {
            throw new ValidationError(sprintf('В эталонной команде должно быть %d игрока(ов), как и в команде турнира, а указано %d.', $teamSize, count($levels)));
        }
        $balance = $balanceId !== null ? $this->balances->find($balanceId) : $this->balances->findDefault();
        if ($balance === null) {
            throw new NotFound($balanceId !== null ? 'Такой шкалы баланса нет.' : 'Шкала баланса по умолчанию ещё не создана.');
        }
        return [$balance->id, Levels::formatReferenceTeam($levels), array_sum($levels)];
    }
}
