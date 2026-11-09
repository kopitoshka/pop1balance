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

    public const REFERENCE_REQUIRED = 'Нужно задать баланс: перечисли уровни самой сильной допустимой команды, '
        . 'например «средний, средний, средний+». Уровней должно быть столько же, сколько игроков в команде.';

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
                    throw new ValidationError('Турнир без баланса сделать нельзя. ' . self::REFERENCE_REQUIRED);
                }
                $reference = $edit->referenceTeam ?? $t->referenceTeam;
                [$balanceId, $reference, $maxPoints] = $this->levelLimit($reference, $edit->balanceId ?? $t->balanceId, $teamSize);
                $fields += ['team_size' => $teamSize, 'balance_id' => $balanceId, 'reference_team' => $reference, 'max_points' => $maxPoints];
            }

            if ($edit->maxPlayers !== null || isset($fields['team_size'])) {
                $fields['max_players'] = $this->validMaxPlayers($edit->maxPlayers ?? $t->maxPlayers, $fields['team_size'] ?? $t->teamSize);
            }

            if ($fields !== [] && $t->status === TournamentStatus::DRAFT) {
                // The changed draft must be shown again before it can be published.
                $fields['card_msg_id'] = null;
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

    /**
     * Automatic status changes made by cron (no actor, no permission check).
     *
     * @return list<Tournament> tournaments whose status changed
     */
    public function autoAdvance(int $finishAfterHours): array
    {
        $now = $this->clock->now();
        $changed = [];
        $past = new DateTimeImmutable('2000-01-01 00:00:00', new \DateTimeZone('UTC'));
        foreach ($this->tournaments->listStartingBetween([TournamentStatus::REGISTRATION, TournamentStatus::CLOSED], $past, $now) as $t) {
            $this->tournaments->update($t->id, ['status' => TournamentStatus::RUNNING]);
            $changed[] = $this->get($t->id);
        }
        $finishBefore = $now->modify("-{$finishAfterHours} hours");
        foreach ($this->tournaments->listStartingBetween([TournamentStatus::RUNNING], $past, $finishBefore) as $t) {
            $this->tournaments->update($t->id, ['status' => TournamentStatus::FINISHED]);
            $changed[] = $this->get($t->id);
        }
        return $changed;
    }

    /**
     * Upcoming tournaments that are due for a reminder: for the smallest offset whose moment
     * has passed and that was not reminded at or after that moment. Returns [tournament, minutes].
     *
     * @param list<int> $offsetsMinutes e.g. [60, 15]
     * @return list<array{0: Tournament, 1: int}>
     */
    public function dueReminders(array $offsetsMinutes): array
    {
        if ($offsetsMinutes === []) {
            return [];
        }
        rsort($offsetsMinutes);
        $now = $this->clock->now();
        $result = [];
        $upcoming = $this->tournaments->listStartingBetween(
            [TournamentStatus::REGISTRATION, TournamentStatus::CLOSED],
            $now,
            $now->modify('+' . $offsetsMinutes[0] . ' minutes'),
        );
        foreach ($upcoming as $t) {
            $due = null;
            foreach ($offsetsMinutes as $minutes) {
                $moment = $t->startsAt->modify("-{$minutes} minutes");
                if ($now >= $moment && ($t->remindedAt === null || $t->remindedAt < $moment)) {
                    $due = $minutes;
                }
            }
            if ($due !== null) {
                $result[] = [$t, $due];
            }
        }
        return $result;
    }

    /** Remembers the confirmation card shown for the current version of a draft. */
    public function setCardMessage(int $tournamentId, ?int $messageId): void
    {
        $this->tournaments->update($tournamentId, ['card_msg_id' => $messageId]);
    }

    public function findByCardMessage(int $chatId, int $messageId): ?Tournament
    {
        return $this->tournaments->findByCardMessage($chatId, $messageId);
    }

    public function setAnnounceMessage(int $tournamentId, int $messageId): void
    {
        $this->tournaments->update($tournamentId, ['announce_msg_id' => $messageId]);
    }

    public function markReminded(int $tournamentId): void
    {
        $this->tournaments->update($tournamentId, ['reminded_at' => $this->clock->now()]);
    }

    /** @return list<Tournament> registration / closed / running, soonest first */
    public function listActive(int $chatId): array
    {
        return $this->tournaments->listByChat($chatId, TournamentStatus::active());
    }

    /**
     * @param list<string> $statuses
     * @return list<Tournament>
     */
    public function listByStatus(int $chatId, array $statuses): array
    {
        return $this->tournaments->listByChat($chatId, $statuses);
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
     * Reference team => [balance_id, canonical reference, max_points]. A reference team is
     * required: tournaments without balance can no longer be created (old rows may still have NULL).
     * A reference without an explicit scale uses the default scale.
     *
     * @return array{0: ?int, 1: ?string, 2: ?int}
     */
    private function levelLimit(?string $referenceTeam, ?int $balanceId, int $teamSize): array
    {
        if ($referenceTeam === null || trim($referenceTeam) === '') {
            throw new ValidationError(self::REFERENCE_REQUIRED);
        }
        $levels = Levels::parseReferenceTeam($referenceTeam);
        if (count($levels) !== $teamSize) {
            throw new ValidationError(sprintf('В команде %d игрока(ов), значит в эталонной команде нужно перечислить %d уровня(ей), а указано %d.', $teamSize, $teamSize, count($levels)));
        }
        $balance = $balanceId !== null ? $this->balances->find($balanceId) : $this->balances->findDefault();
        if ($balance === null) {
            throw new NotFound($balanceId !== null ? 'Такой шкалы баланса нет.' : 'Шкала баланса по умолчанию ещё не создана.');
        }
        return [$balance->id, Levels::formatReferenceTeam($levels), array_sum($levels)];
    }
}
