<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Domain\TournamentService;
use P1Bot\Domain\TournamentStatus;
use P1Bot\View\AnnouncementView;

/**
 * The pretty tournament list for /tournaments and the AI: one block per tournament,
 * packed into messages under Telegram's 4096 characters.
 */
final class TournamentListing
{
    private const TITLE = '📋 <b>Турниры</b>';
    private const SEPARATOR = "\n\n➖➖➖➖➖\n\n";

    public function __construct(
        private TournamentService $tournaments,
        private Announcer $announcer,
    ) {
    }

    /**
     * @param bool $withDrafts admins also see their unpublished drafts
     * @return list<string> HTML messages; empty when there is nothing to show
     */
    public function messages(int $chatId, bool $withDrafts, int $limit = AnnouncementView::MESSAGE_LIMIT): array
    {
        $blockLimit = $limit - mb_strlen(self::TITLE) - mb_strlen(self::SEPARATOR);
        $blocks = [];
        foreach ($this->tournaments->listActive($chatId) as $t) {
            $blocks[] = $this->announcer->listBlock($t, $blockLimit);
        }
        if ($withDrafts) {
            foreach ($this->tournaments->listByStatus($chatId, [TournamentStatus::DRAFT]) as $t) {
                $this->announcer->number($t);
                $blocks[] = $this->announcer->listBlock($t, $blockLimit, '<i>Черновик. Опубликовать: /open ' . $t->number . '</i>');
            }
        }
        if ($blocks === []) {
            return [];
        }

        $messages = [];
        $current = self::TITLE;
        foreach ($blocks as $block) {
            if (mb_strlen($current . self::SEPARATOR . $block) > $limit) {
                $messages[] = $current;
                $current = $block;
                continue;
            }
            $current .= ($current === self::TITLE ? "\n\n" : self::SEPARATOR) . $block;
        }
        $messages[] = $current;
        return $messages;
    }
}
