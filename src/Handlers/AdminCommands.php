<?php

declare(strict_types=1);

namespace P1Bot\Handlers;

use P1Bot\App\Announcer;
use P1Bot\App\DraftCards;
use P1Bot\App\PlayerResolver;
use P1Bot\App\TournamentResolver;
use P1Bot\Domain\BalanceService;
use P1Bot\Domain\CreateTournament;
use P1Bot\Domain\EditTournament;
use P1Bot\Domain\Exception\DomainError;
use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Levels;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\MoscowTime;
use P1Bot\Domain\TournamentService;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Repository\BalanceRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Clock;
use P1Bot\Telegram\BotIdentity;
use P1Bot\Telegram\TelegramApi;
use P1Bot\View\Html;
use P1Bot\View\Messages;
use P1Bot\View\Texts;

/** Slash commands: the fallback that works without AI. */
final class AdminCommands
{
    private const PUBLIC = ['help', 'start', 'tournaments', 'info'];
    private const ADMIN = ['new', 'edit', 'cancel', 'open', 'close', 'level', 'balance'];

    /** Tournaments an admin command may target when no id is given. */
    private const MANAGEABLE = [TournamentStatus::DRAFT, TournamentStatus::REGISTRATION, TournamentStatus::CLOSED, TournamentStatus::RUNNING];

    public function __construct(
        private TelegramApi $api,
        private BotIdentity $bot,
        private TournamentService $tournaments,
        private BalanceService $balances,
        private BalanceRepository $balanceRepo,
        private PlayerRepository $players,
        private TournamentResolver $resolver,
        private PlayerResolver $playerResolver,
        private Announcer $announcer,
        private Clock $clock,
        private DraftCards $drafts,
    ) {
    }

    public function supports(string $command): bool
    {
        return in_array($command, self::PUBLIC, true) || in_array($command, self::ADMIN, true);
    }

    public function handle(Context $ctx, string $command, string $args): void
    {
        try {
            if (in_array($command, self::ADMIN, true) && !$ctx->isAdmin) {
                throw new PermissionDenied('Эта команда только для админов чата. Посмотреть турниры: /tournaments');
            }
            $reply = $this->run($ctx, $command, $args);
        } catch (DomainError $e) {
            $reply = Html::e($e->getMessage());
        }
        if ($reply !== '') {
            $this->api->sendMessage($ctx->chatId(), $reply, $ctx->message->messageId);
        }
    }

    /** @return string HTML reply ('' = nothing to say) */
    private function run(Context $ctx, string $command, string $args): string
    {
        switch ($command) {
            case 'help':
            case 'start':
                return Texts::help($this->bot->username, $ctx->isAdmin);
            case 'tournaments':
                return $this->listTournaments($ctx);
            case 'info':
                [$id] = self::splitId($args);
                $t = $this->resolver->resolve($ctx->chatId(), $id, TournamentStatus::active());
                return $this->announcer->text($t);
            case 'new':
                return $this->create($ctx, $args);
            case 'edit':
                return $this->edit($ctx, $args);
            case 'open':
                return $this->open($ctx, $args);
            case 'close':
                $t = $this->tournaments->closeRegistration($ctx->actor->id, $this->target($ctx, $args)->id);
                $this->announcer->refresh($t->id);
                return sprintf('Регистрация на %s закрыта. Состав зафиксирован, но снять команду ещё можно.', Html::b($t->title));
            case 'cancel':
                $t = $this->tournaments->cancel($ctx->actor->id, $this->target($ctx, $args)->id);
                $this->announcer->refresh($t->id);
                return sprintf('Турнир %s отменён. Жаль!', Html::b($t->title));
            case 'level':
                return $this->setLevel($ctx, $args);
            case 'balance':
                return $this->showBalance($ctx, $args);
        }
        return '';
    }

    private function listTournaments(Context $ctx): string
    {
        $active = $this->tournaments->listActive($ctx->chatId());
        $lines = [];
        foreach ($active as $t) {
            $lines[] = '• ' . $this->line($t);
        }
        if ($ctx->isAdmin) {
            foreach ($this->tournaments->listByStatus($ctx->chatId(), [TournamentStatus::DRAFT]) as $t) {
                $lines[] = '• ' . $this->line($t) . ' — <i>черновик, открыть: /open ' . $t->id . '</i>';
            }
        }
        if ($lines === []) {
            return 'Сейчас активных турниров нет.' . ($ctx->isAdmin ? ' Создать: /new 23.11 20:00 | Название | 3 | средний, средний, средний+ | 24' : '');
        }
        return "<b>Турниры:</b>\n" . implode("\n", $lines);
    }

    private function line(Tournament $t): string
    {
        return '№' . $t->id . ' ' . Html::e(Messages::tournamentLine($t, $this->tournaments->lineup($t->id)));
    }

    /** /new 23.11 20:00 | Название | 3 | средний, средний, средний+ | 24 => draft card with confirmation */
    private function create(Context $ctx, string $args): string
    {
        $parts = array_map('trim', explode('|', $args));
        if (count($parts) < 4 || $parts[0] === '' || $parts[1] === '' || $parts[3] === '') {
            throw new ValidationError(
                "Создать турнир можно одной строкой:\n/new 23.11 20:00 | Название | 3 | средний, средний, средний+ | 24\n"
                . 'Дата и время по МСК | название | игроков в команде (2–4) | эталонная команда: уровни самой сильной допустимой команды, '
                . 'по одному на игрока (можно цифрами: 3,3,4) | лимит участников (по умолчанию 24).'
                . "\nИли просто напиши мне, какой турнир нужен, — я уточню детали.",
            );
        }
        $startsAt = MoscowTime::parseHuman($parts[0], $this->clock->now());
        $teamSize = self::intArg($parts[2], 'размер команды');
        $reference = $parts[3];
        $maxPlayers = isset($parts[4]) && $parts[4] !== '' ? self::intArg($parts[4], 'лимит участников') : null;

        $t = $this->tournaments->create(new CreateTournament(
            $ctx->chatId(),
            $ctx->actor->id,
            $parts[1],
            MoscowTime::toInput($startsAt),
            $teamSize,
            $reference,
            null,
            $maxPlayers,
        ));

        $this->drafts->show($t->id, $ctx->message->messageId, $ctx->actor->id, [
            ['role' => 'user', 'content' => '/new ' . $args],
        ]);
        return '';
    }

    /** /edit [5] поле значение */
    private function edit(Context $ctx, string $args): string
    {
        [$id, $rest] = self::splitId($args);
        if (!preg_match('/^(\S+)\s*(.*)$/us', $rest, $m)) {
            throw new ValidationError("Что поменять? Например:\n/edit 5 время 23.11 21:00\n/edit 5 название Кубок выходного дня\n/edit 5 лимит 32\n/edit 5 размер 2\n/edit 5 эталон средний, средний, средний+");
        }
        $field = mb_strtolower($m[1]);
        $value = trim($m[2]);
        $t = $this->resolver->resolve($ctx->chatId(), $id, self::MANAGEABLE);

        switch ($field) {
            case 'название':
            case 'title':
                $edit = new EditTournament($value);
                break;
            case 'время':
            case 'дата':
            case 'time':
                $edit = new EditTournament(null, MoscowTime::toInput(MoscowTime::parseHuman($value, $this->clock->now())));
                break;
            case 'размер':
            case 'size':
                $edit = new EditTournament(null, null, self::intArg($value, 'размер команды'));
                break;
            case 'эталон':
            case 'reference':
                $edit = new EditTournament(null, null, null, $value);
                break;
            case 'лимит':
            case 'limit':
                $edit = new EditTournament(null, null, null, null, null, self::intArg($value, 'лимит участников'));
                break;
            case 'баланс':
            case 'balance':
                $balanceId = TournamentResolver::parseId($value) ?? throw new ValidationError('Укажи номер шкалы баланса, например: /edit 5 баланс 2');
                $edit = new EditTournament(null, null, null, null, $balanceId);
                break;
            default:
                throw new ValidationError(sprintf('Не знаю поле «%s». Можно: название, время, размер, эталон, лимит, баланс.', $field));
        }

        $t = $this->tournaments->edit($ctx->actor->id, $t->id, $edit);
        if ($t->status === TournamentStatus::DRAFT) {
            $this->drafts->show($t->id, $ctx->message->messageId, $ctx->actor->id, [
                ['role' => 'user', 'content' => '/edit ' . $args],
            ]);
            return '';
        }
        $this->announcer->refresh($t->id);
        return "Обновил:\n" . Html::e(Messages::tournamentLine($t, $this->tournaments->lineup($t->id)));
    }

    private function open(Context $ctx, string $args): string
    {
        $target = $this->target($ctx, $args, [TournamentStatus::DRAFT, TournamentStatus::CLOSED]);
        if ($target->status === TournamentStatus::DRAFT) {
            // Same confirmation as creation: card «Публикуем или что-то поправить?», publish only after a clear yes.
            $this->tournaments->assertCanManage($ctx->actor->id, $target->id);
            $this->drafts->show($target->id, $ctx->message->messageId, $ctx->actor->id, [
                ['role' => 'user', 'content' => '/open ' . $args],
            ]);
            return '';
        }
        $t = $this->tournaments->openRegistration($ctx->actor->id, $target->id);
        // A reopened tournament gets a fresh announcement; buttons on the old one keep working.
        $this->announcer->publish($t->id);
        return '';
    }

    /** /level @user 4 [balance] */
    private function setLevel(Context $ctx, string $args): string
    {
        $mentions = $ctx->message->mentionedUsers($this->bot->username);
        if ($mentions === []) {
            throw new ValidationError("Кому ставим уровень? Например: /level @vasya 4\nУровни: " . self::levelsHint());
        }
        $first = $mentions[0];
        $player = isset($first['user'])
            ? $this->playerResolver->fromUser($first['user'])
            : $this->playerResolver->fromReference('@' . $first['username'], $ctx->actor);
        $rest = trim((string) preg_replace('/' . preg_quote($first['text'], '/') . '/u', '', $args, 1));

        $tokens = preg_split('/\s+/u', $rest, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $balanceId = null;
        if (count($tokens) >= 2 && TournamentResolver::parseId((string) end($tokens)) !== null) {
            $balanceId = TournamentResolver::parseId((string) array_pop($tokens));
        }
        $level = Levels::parse(implode('', $tokens));
        if ($level === null) {
            throw new ValidationError('Уровень от 1 до 6 или название: ' . self::levelsHint());
        }

        $balance = $this->balances->setLevel($ctx->chatId(), $ctx->actor->id, $player->id, $level, $balanceId);
        return sprintf(
            'Записал: %s — %s (%d) в шкале «%s».',
            Html::e($player->mention()),
            Levels::name($level),
            $level,
            Html::e($balance->name),
        );
    }

    /** /balance [id] */
    private function showBalance(Context $ctx, string $args): string
    {
        $id = TournamentResolver::parseId($args);
        if ($id === null) {
            $own = $this->balanceRepo->listByOwner($ctx->actor->id);
            $balance = $own[0] ?? $this->balances->findDefault();
            if ($balance === null) {
                throw new ValidationError('Шкал баланса пока нет.');
            }
        } else {
            $balance = $this->balances->get($id);
        }

        $rows = [];
        foreach ($this->balances->effectiveRatings($balance->id) as $playerId => $rating) {
            $player = $this->players->find($playerId);
            $rows[] = sprintf(
                '• %s — %s (%d)%s',
                Html::e($player !== null ? $player->mention() : '#' . $playerId),
                Levels::name($rating['level']),
                $rating['level'],
                $rating['balance_id'] !== $balance->id ? ' <i>унаследовано</i>' : '',
            );
        }
        $owner = $this->players->find($balance->ownerId);
        $header = sprintf(
            '<b>Шкала «%s»</b> (№%d, владелец %s%s)',
            Html::e($balance->name),
            $balance->id,
            Html::e($owner !== null ? $owner->mention() : '?'),
            $balance->parentId !== null ? ', основана на №' . $balance->parentId : '',
        );
        if ($rows === []) {
            return $header . "\nОценок пока нет. Все игроки считаются средними (3).";
        }
        return $header . "\n" . implode("\n", $rows) . "\n\nОстальные игроки — средние (3).";
    }

    /** @param list<string>|null $statuses */
    private function target(Context $ctx, string $args, ?array $statuses = null): Tournament
    {
        [$id] = self::splitId($args);
        return $this->resolver->resolve($ctx->chatId(), $id, $statuses ?? self::MANAGEABLE);
    }

    /** @return array{0: ?int, 1: string} leading tournament id and the rest */
    private static function splitId(string $args): array
    {
        $args = trim($args);
        if (preg_match('/^([#№]?\d{1,9})(?:\s+(.*))?$/us', $args, $m)) {
            return [TournamentResolver::parseId($m[1]), trim($m[2] ?? '')];
        }
        return [null, $args];
    }

    private static function intArg(string $value, string $what): int
    {
        if (!preg_match('/^\d{1,4}$/', trim($value))) {
            throw new ValidationError(sprintf('Не понял %s: «%s». Нужно число.', $what, $value));
        }
        return (int) $value;
    }

    private static function levelsHint(): string
    {
        $parts = [];
        foreach (Levels::all() as $level => $name) {
            $parts[] = "$level — $name";
        }
        return implode(', ', $parts);
    }
}
