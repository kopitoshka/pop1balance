<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Domain\TournamentService;
use P1Bot\Domain\TournamentStatus;
use P1Bot\View\AnnouncementView;
use P1Bot\View\Html;

/**
	The pretty tournament list for /tournaments and the AI: one block per tournament,
	packed into messages under Telegram's 4096 characters.
*/
class TournamentListing {
	protected const TITLE = '📋 <b>Турниры</b>';
	protected const SEPARATOR = "\n\n➖➖➖➖➖\n\n";

	protected const PRIVATE_DRAFT = '<i>Черновик, его видишь только ты. Опубликовать: /open %d в группе</i>';

	public function __construct(
		protected TournamentService $tournaments,
		protected Announcer $announcer,
	) {
	}

	/**
		@param bool $with_drafts admins also see their unpublished drafts
		@return list<string> HTML messages; empty when there is nothing to show
	*/
	public function messages(int $chat_id, bool $with_drafts, int $limit = AnnouncementView::MESSAGE_LIMIT): array {
		$block_limit = $limit - mb_strlen(self::TITLE) - mb_strlen(self::SEPARATOR);
		$blocks = [];
		foreach ($this->tournaments->listActive($chat_id) as $t) {
			$blocks[] = $this->announcer->listBlock($t, $block_limit);
		}
		if ($with_drafts) {
			foreach ($this->tournaments->listByStatus($chat_id, [TournamentStatus::DRAFT]) as $t) {
				$this->announcer->number($t);
				$blocks[] = $this->announcer->listBlock(
					$t,
					$block_limit,
					'<i>Черновик. Опубликовать: /open ' . $t->number . '</i>',
				);
			}
		}
		return $this->pack($blocks, $limit);
	}

	/**
		The list for a private chat: published tournaments of every group the bot is in, plus drafts
		of this player (all drafts for the owner). Numbers are the group's own short numbers, so with
		several groups each group gets a header with its name.

		@param array<int, string> $groups chat_id => title
		@return list<string> HTML messages; empty when there is nothing to show
	*/
	public function privateMessages(
		array $groups,
		int $player_id,
		bool $is_owner,
		int $limit = AnnouncementView::MESSAGE_LIMIT,
	): array {
		$block_limit = $limit - mb_strlen(self::TITLE) - mb_strlen(self::SEPARATOR);
		$with_headers = count($groups) > 1;
		$blocks = [];
		foreach ($groups as $chat_id => $title) {
			$group_blocks = [];
			foreach ($this->tournaments->listActive($chat_id) as $t) {
				$group_blocks[] = $this->announcer->listBlock($t, $block_limit);
			}
			foreach ($this->tournaments->listByStatus($chat_id, [TournamentStatus::DRAFT]) as $t) {
				if (!$is_owner && $t->organizer_id !== $player_id) {
					continue;
				}
				$this->announcer->number($t);
				$group_blocks[] = $this->announcer->listBlock(
					$t,
					$block_limit,
					sprintf(self::PRIVATE_DRAFT, (int) $t->number),
				);
			}
			if ($group_blocks !== [] && $with_headers) {
				$group_blocks[0] = '👥 Группа <b>' . Html::e($title !== '' ? $title : (string) $chat_id) . "</b>

" . $group_blocks[0];
			}
			$blocks = array_merge($blocks, $group_blocks);
		}
		return $this->pack($blocks, $limit);
	}

	/**
		Packs blocks into messages under the limit, the first one starting with the title.

		@param list<string> $blocks
		@return list<string>
	*/
	protected function pack(array $blocks, int $limit): array {
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
