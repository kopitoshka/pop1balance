<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Domain\AccessPolicy;
use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\TournamentService;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Repository\BalanceRepository;
use P1Bot\Repository\ConversationRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Clock;
use P1Bot\Support\Logger;
use P1Bot\Telegram\TelegramApi;
use P1Bot\View\TournamentCard;
use Throwable;

/**
 * Draft confirmation flow: show the card with «Публикуем или что-то поправить?» and publish
 * only after an explicit confirmation of the version that was shown.
 */
final class DraftCards
{
    public function __construct(
        private TelegramApi $api,
        private TournamentService $tournaments,
        private PlayerRepository $players,
        private BalanceRepository $balances,
        private ConversationRepository $conversations,
        private Announcer $announcer,
        private AccessPolicy $access,
        private Clock $clock,
        private Logger $logger,
        private int $conversationTtlMinutes = 30,
    ) {
    }

    public function cardText(Tournament $t): string
    {
        return TournamentCard::render(
            $t,
            $this->players->find($t->organizerId),
            $t->balanceId !== null ? $this->balances->find($t->balanceId) : null,
        );
    }

    /**
     * Sends the card as a reply and remembers it as the version to confirm.
     *
     * @param list<array<string, mixed>> $history dialog to continue when the organizer replies to the card
     * @return int message id of the card
     */
    public function show(int $tournamentId, int $replyTo, int $actorId, array $history = []): int
    {
        $t = $this->tournaments->get($tournamentId);
        if ($t->status !== TournamentStatus::DRAFT) {
            throw new ValidationError('Этот турнир уже не черновик.');
        }
        $text = $this->cardText($t);
        $messageId = $this->api->sendMessage($t->chatId, $text, $replyTo, TournamentCard::buttons($t->id));
        $this->tournaments->setCardMessage($t->id, $messageId, $actorId);

        $history[] = ['role' => 'assistant', 'content' => self::plain($text)];
        $this->conversations->save(
            null,
            $t->chatId,
            $actorId,
            $messageId,
            array_slice($history, -12),
            $this->clock->now()->modify("+{$this->conversationTtlMinutes} minutes"),
        );
        return $messageId;
    }

    /**
     * Publishes a confirmed draft: opens registration and posts the announcement.
     *
     * @param int|null $cardMessageId the card the confirmation refers to (button / reply), if known
     */
    public function publish(int $actorId, int $tournamentId, ?int $cardMessageId = null): Tournament
    {
        $t = $this->tournaments->get($tournamentId);
        if ($t->status !== TournamentStatus::DRAFT) {
            throw new ValidationError(sprintf('Турнир «%s» уже не черновик (%s).', $t->title, TournamentStatus::label($t->status)));
        }
        if ($t->cardMsgId === null) {
            throw new ValidationError('Сначала нужно показать актуальную карточку турнира и получить подтверждение.');
        }
        if ($cardMessageId !== null && $cardMessageId !== $t->cardMsgId) {
            throw new ValidationError('Эта карточка устарела: турнир с тех пор меняли. Подтверди последнюю карточку.');
        }
        if (!$this->canConfirm($t, $actorId)) {
            throw new PermissionDenied('Опубликовать турнир может только его организатор или тот, кто попросил показать карточку.');
        }

        $card = $this->cardText($t);
        $published = $this->tournaments->openRegistration($actorId, $t->id);
        $this->announcer->publish($published->id);
        try {
            $this->api->editMessageText($t->chatId, (int) $t->cardMsgId, TournamentCard::published($card), ['reply_markup' => ['inline_keyboard' => []]]);
        } catch (Throwable $e) {
            $this->logger->exception($e, 'card published ' . $t->id);
        }
        return $this->tournaments->get($t->id);
    }

    /**
     * Who may confirm a draft card: the organizer, the admin the current card was shown to
     * (e.g. who ran /open or /edit) and the bot owner.
     */
    public function canConfirm(Tournament $t, int $actorId): bool
    {
        return $t->organizerId === $actorId
            || ($t->cardShownTo !== null && $t->cardShownTo === $actorId)
            || $this->access->isOwner($actorId);
    }

    private static function plain(string $html): string
    {
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
