<?php

declare(strict_types=1);

namespace P1Bot\Ai;

use P1Bot\App\Announcer;
use P1Bot\App\PlayerResolver;
use P1Bot\App\TournamentResolver;
use P1Bot\Domain\BalanceService;
use P1Bot\Domain\CreateTournament;
use P1Bot\Domain\EditTournament;
use P1Bot\Domain\Exception\DomainError;
use P1Bot\Domain\Exception\NotFound;
use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Levels;
use P1Bot\Domain\Model\TeamEntry;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\MoscowTime;
use P1Bot\Domain\TeamService;
use P1Bot\Domain\TournamentService;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Handlers\Context;
use P1Bot\Repository\BalanceRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Clock;
use P1Bot\Support\Logger;
use P1Bot\View\Messages;
use Throwable;

/**
 * Executes model function calls through the domain services.
 * The model never decides permissions: services and the admin check below do.
 * Results are plain arrays sent back to the model as JSON.
 */
final class Dispatcher
{
    public function __construct(
        private TournamentService $tournaments,
        private TeamService $teams,
        private BalanceService $balances,
        private BalanceRepository $balanceRepo,
        private PlayerRepository $players,
        private TournamentResolver $resolver,
        private PlayerResolver $playerResolver,
        private Announcer $announcer,
        private Clock $clock,
        private Logger $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function dispatch(string $tool, array $args, Context $ctx): array
    {
        try {
            if (in_array($tool, Tools::ADMIN_TOOLS, true) && !$ctx->isAdmin) {
                throw new PermissionDenied('Это действие доступно только админам чата.');
            }
            $result = $this->run($tool, $args, $ctx);
            if (in_array($tool, Tools::MUTATING, true) && isset($result['tournament_id'])) {
                $this->announcer->refresh((int) $result['tournament_id']);
            }
            return ['ok' => true] + $result;
        } catch (DomainError $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            $this->logger->exception($e, 'tool ' . $tool);
            return ['ok' => false, 'error' => 'Внутренняя ошибка, действие не выполнено.'];
        }
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function run(string $tool, array $a, Context $ctx): array
    {
        $actor = $ctx->actor;
        switch ($tool) {
            case 'list_tournaments':
                return ['tournaments' => array_map(function (Tournament $t): string {
                    return $this->line($t);
                }, $this->tournaments->listActive($ctx->chatId()))];

            case 'tournament_info':
                $t = $this->tournament($ctx, $a, TournamentStatus::active());
                return ['tournament_id' => $t->id, 'info' => $this->line($t), 'lineup' => self::plain($this->announcer->lineupText($t))];

            case 'register_team':
                $t = $this->tournament($ctx, $a);
                $ids = [];
                foreach ((array) ($a['players'] ?? []) as $ref) {
                    $ids[] = $this->playerResolver->fromReference((string) $ref, $actor)->id;
                }
                $entry = $this->teams->createTeam($t->id, $actor->id, (string) ($a['team_name'] ?? ''), $ids);
                return $this->teamResult($t, $entry, 'Команда создана.');

            case 'add_player':
                [$t, $entry] = $this->team($ctx, $a);
                $player = $this->playerResolver->fromReference((string) ($a['player'] ?? ''), $actor);
                return $this->teamResult($t, $this->teams->addPlayer($actor->id, $entry->team->id, $player->id), 'Игрок добавлен.');

            case 'remove_player':
                [$t, $entry] = $this->team($ctx, $a);
                $player = $this->playerResolver->fromReference((string) ($a['player'] ?? ''), $actor);
                return $this->teamResult($t, $this->teams->removePlayer($actor->id, $entry->team->id, $player->id), 'Игрок убран.');

            case 'replace_player':
                [$t, $entry] = $this->team($ctx, $a);
                $out = $this->playerResolver->fromReference((string) ($a['out_player'] ?? ''), $actor);
                $in = $this->playerResolver->fromReference((string) ($a['in_player'] ?? ''), $actor);
                return $this->teamResult($t, $this->teams->replacePlayer($actor->id, $entry->team->id, $out->id, $in->id), 'Замена сделана.');

            case 'rename_team':
                [$t, $entry] = $this->team($ctx, $a);
                return $this->teamResult($t, $this->teams->renameTeam($actor->id, $entry->team->id, (string) ($a['new_name'] ?? '')), 'Команда переименована.');

            case 'transfer_captain':
                [$t, $entry] = $this->team($ctx, $a);
                $player = $this->playerResolver->fromReference((string) ($a['player'] ?? ''), $actor);
                return $this->teamResult($t, $this->teams->transferCaptain($actor->id, $entry->team->id, $player->id), 'Капитан сменился.');

            case 'withdraw_team':
                [$t, $entry] = $this->team($ctx, $a);
                $this->teams->withdrawTeam($actor->id, $entry->team->id);
                return ['tournament_id' => $t->id, 'message' => sprintf('Команда «%s» снята с турнира.', $entry->team->name), 'summary' => $this->tournaments->lineup($t->id)->summary()];

            case 'leave_team':
                $t = $this->tournament($ctx, $a);
                $entry = $this->teams->teamOf($t->id, $actor->id) ?? throw new NotFound('Ты не состоишь в команде на этом турнире.');
                return $this->teamResult($t, $this->teams->leaveTeam($actor->id, $entry->team->id), 'Ты вышел из команды.');

            case 'join_solo':
                $t = $this->tournament($ctx, $a);
                $this->teams->joinSolo($t->id, $actor->id);
                return ['tournament_id' => $t->id, 'placement' => $this->placementLabel($t, $actor->id), 'summary' => $this->tournaments->lineup($t->id)->summary()];

            case 'leave_solo':
                $t = $this->tournament($ctx, $a);
                $this->teams->leaveSolo($t->id, $actor->id);
                return ['tournament_id' => $t->id, 'message' => 'Соло-заявка снята.'];

            case 'my_status':
                return ['status' => $this->statusLines($ctx)];

            case 'ask_clarification':
                return ['note' => 'Задай пользователю этот вопрос своими словами.', 'question' => (string) ($a['question'] ?? '')];

            case 'create_tournament':
                $startsAt = MoscowTime::parseHuman((string) ($a['starts_at_msk'] ?? ''), $this->clock->now());
                $reference = isset($a['reference_team']) && trim((string) $a['reference_team']) !== '' ? (string) $a['reference_team'] : null;
                $t = $this->tournaments->create(new CreateTournament(
                    $ctx->chatId(),
                    $actor->id,
                    (string) ($a['title'] ?? ''),
                    MoscowTime::toInput($startsAt),
                    (int) ($a['team_size'] ?? 0),
                    $reference,
                    null,
                    isset($a['max_players']) ? (int) $a['max_players'] : null,
                ));
                if (!empty($a['open_registration'])) {
                    $t = $this->tournaments->openRegistration($actor->id, $t->id);
                    $this->announcer->publish($t->id);
                }
                return ['tournament_id' => $t->id, 'info' => $this->line($t), 'status' => TournamentStatus::label($t->status)];

            case 'edit_tournament':
                $t = $this->tournament($ctx, $a, [TournamentStatus::DRAFT, TournamentStatus::REGISTRATION, TournamentStatus::CLOSED]);
                $time = isset($a['starts_at_msk']) ? MoscowTime::toInput(MoscowTime::parseHuman((string) $a['starts_at_msk'], $this->clock->now())) : null;
                $t = $this->tournaments->edit($actor->id, $t->id, new EditTournament(
                    isset($a['title']) ? (string) $a['title'] : null,
                    $time,
                    isset($a['team_size']) ? (int) $a['team_size'] : null,
                    isset($a['reference_team']) && $a['reference_team'] !== '' ? (string) $a['reference_team'] : null,
                    null,
                    isset($a['max_players']) ? (int) $a['max_players'] : null,
                    !empty($a['remove_level_limit']),
                ));
                return ['tournament_id' => $t->id, 'info' => $this->line($t)];

            case 'cancel_tournament':
                $t = $this->tournaments->cancel($actor->id, $this->tournament($ctx, $a, [TournamentStatus::DRAFT, TournamentStatus::REGISTRATION, TournamentStatus::CLOSED])->id);
                return ['tournament_id' => $t->id, 'message' => 'Турнир отменён.'];

            case 'open_registration':
                $t = $this->tournaments->openRegistration($actor->id, $this->tournament($ctx, $a, [TournamentStatus::DRAFT, TournamentStatus::CLOSED])->id);
                $this->announcer->publish($t->id);
                return ['tournament_id' => $t->id, 'message' => 'Регистрация открыта, анонс опубликован в чате.'];

            case 'close_registration':
                $t = $this->tournaments->closeRegistration($actor->id, $this->tournament($ctx, $a, [TournamentStatus::REGISTRATION])->id);
                return ['tournament_id' => $t->id, 'message' => 'Регистрация закрыта.'];

            case 'set_max_players':
                $t = $this->tournaments->setMaxPlayers($actor->id, $this->tournament($ctx, $a)->id, (int) ($a['max_players'] ?? 0));
                return ['tournament_id' => $t->id, 'summary' => $this->tournaments->lineup($t->id)->summary()];

            case 'set_level':
                $player = $this->playerResolver->fromReference((string) ($a['player'] ?? ''), $actor);
                $level = Levels::parse((string) ($a['level'] ?? '')) ?? throw new ValidationError('Уровень должен быть от 1 до 6 или названием уровня.');
                $balance = $this->balances->setLevel($ctx->chatId(), $actor->id, $player->id, $level, isset($a['balance_id']) ? (int) $a['balance_id'] : null);
                return ['message' => sprintf('%s: %s (%d) в шкале «%s».', $player->mention(), Levels::name($level), $level, $balance->name)];

            case 'create_balance':
                $balance = $this->balances->create($ctx->chatId(), $actor->id, (string) ($a['name'] ?? ''), isset($a['based_on_balance_id']) ? (int) $a['based_on_balance_id'] : null);
                return ['balance_id' => $balance->id, 'message' => sprintf('Шкала «%s» создана (№%d).', $balance->name, $balance->id)];

            case 'show_balance':
                if (isset($a['balance_id'])) {
                    $balance = $this->balances->get((int) $a['balance_id']);
                } else {
                    $balance = $this->balanceRepo->listByOwner($actor->id)[0] ?? $this->balances->findDefault();
                    if ($balance === null) {
                        throw new NotFound('Шкал баланса пока нет.');
                    }
                }
                $ratings = [];
                foreach ($this->balances->effectiveRatings($balance->id) as $playerId => $rating) {
                    $p = $this->players->find($playerId);
                    $ratings[] = sprintf('%s — %s (%d)', $p !== null ? $p->mention() : '#' . $playerId, Levels::name($rating['level']), $rating['level']);
                }
                return ['balance_id' => $balance->id, 'name' => $balance->name, 'ratings' => $ratings, 'note' => 'Остальные игроки — средние (3).'];
        }
        throw new ValidationError('Такого действия нет.');
    }

    /**
     * @param array<string, mixed> $a
     * @param list<string>|null $statuses
     */
    private function tournament(Context $ctx, array $a, ?array $statuses = null): Tournament
    {
        $id = isset($a['tournament_id']) && $a['tournament_id'] !== '' ? (int) $a['tournament_id'] : null;
        return $this->resolver->resolve($ctx->chatId(), $id, $statuses ?? [TournamentStatus::REGISTRATION, TournamentStatus::CLOSED]);
    }

    /**
     * Team by name, or the team of the author.
     *
     * @param array<string, mixed> $a
     * @return array{0: Tournament, 1: TeamEntry}
     */
    private function team(Context $ctx, array $a): array
    {
        $t = $this->tournament($ctx, $a);
        $name = trim((string) ($a['team_name'] ?? ''));
        if ($name !== '') {
            $entry = $this->teams->findByName($t->id, $name);
            if ($entry === null) {
                throw new NotFound(sprintf('Команды «%s» на этом турнире нет.', $name));
            }
            return [$t, $entry];
        }
        $entry = $this->teams->teamOf($t->id, $ctx->actor->id);
        if ($entry === null) {
            throw new NotFound('Ты не состоишь в команде на этом турнире.');
        }
        return [$t, $entry];
    }

    /** @return array<string, mixed> */
    private function teamResult(Tournament $t, TeamEntry $entry, string $message): array
    {
        $members = [];
        foreach ($entry->members as $member) {
            $p = $this->players->find($member->playerId);
            $members[] = ($p !== null ? $p->mention() : '#' . $member->playerId) . ($member->playerId === $entry->team->captainId ? ' (капитан)' : '');
        }
        $t = $this->tournaments->get($t->id);
        $placement = null;
        if ($entry->team->isRegistered()) {
            $placement = $this->tournaments->lineup($t->id)->placementOf($entry->team->captainId) === 'reserve' ? 'резерв' : 'основной состав';
        }
        return [
            'tournament_id' => $t->id,
            'message' => $message,
            'team' => $entry->team->name,
            'members' => $members,
            'size' => sprintf('%d из %d', $entry->size(), $t->teamSize),
            'registered' => $entry->team->isRegistered(),
            'placement' => $placement ?? 'команда ещё собирается: не хватает игроков',
            'points' => $t->hasLevelLimit() ? sprintf('%d из %d', $entry->points(), (int) $t->maxPoints) : 'без лимита',
        ];
    }

    private function placementLabel(Tournament $t, int $playerId): string
    {
        $placement = $this->tournaments->lineup($t->id)->placementOf($playerId);
        return $placement === 'reserve' ? 'резерв' : ($placement === 'forming' ? 'команда собирается' : 'основной состав');
    }

    /** @return list<string> */
    public function statusLines(Context $ctx): array
    {
        $lines = [];
        foreach ($this->tournaments->listActive($ctx->chatId()) as $t) {
            $entry = $this->teams->teamOf($t->id, $ctx->actor->id);
            $placement = $this->tournaments->lineup($t->id)->placementOf($ctx->actor->id);
            if ($placement === null) {
                continue;
            }
            $where = $entry !== null
                ? sprintf('в команде «%s»%s', $entry->team->name, $entry->team->captainId === $ctx->actor->id ? ' (капитан)' : '')
                : 'соло';
            $lines[] = sprintf('№%d «%s»: %s, %s', $t->id, $t->title, $where, $this->placementLabel($t, $ctx->actor->id));
        }
        return $lines === [] ? ['Нигде не заявлен.'] : $lines;
    }

    private function line(Tournament $t): string
    {
        return '№' . $t->id . ' ' . Messages::tournamentLine($t, $this->tournaments->lineup($t->id)) . ', ' . TournamentStatus::label($t->status);
    }

    private static function plain(string $html): string
    {
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
