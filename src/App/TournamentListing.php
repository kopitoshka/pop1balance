<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Domain\Model\Tournament;
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

	protected const PRIVATE_DRAFT = '<i>Черновик, его видишь только ты. Опубликовать: /open %d</i>';

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
		$current_group = null;
		foreach ($this->privateTournaments($groups, $player_id, $is_owner) as $t) {
			$footer = $t->status === TournamentStatus::DRAFT ? sprintf(self::PRIVATE_DRAFT, (int) $t->number) : '';
			$block = $this->announcer->listBlock($t, $block_limit, $footer);
			if ($with_headers && $t->chat_id !== $current_group) {
				$title = $groups[$t->chat_id] ?? '';
				$block = '👥 Группа <b>' . Html::e($title !== '' ? $title : (string) $t->chat_id) . "</b>\n\n" . $block;
			}
			$current_group = $t->chat_id;
			$blocks[] = $block;
		}
		return $this->pack($blocks, $limit);
	}

	/**
		Tournaments for the private list, numbered, group by group (published first, then drafts).

		@param array<int, string> $groups
		@return list<Tournament>
	*/
	public function privateTournaments(array $groups, int $player_id, bool $is_owner): array {
		$result = [];
		foreach (array_keys($groups) as $chat_id) {
			foreach ($this->tournaments->listActive($chat_id) as $t) {
				$result[] = $this->announcer->number($t);
			}
			foreach ($this->tournaments->listByStatus($chat_id, [TournamentStatus::DRAFT]) as $t) {
				if ($is_owner || $t->organizer_id === $player_id) {
					$result[] = $this->announcer->number($t);
				}
			}
		}
		return $result;
	}

	/**
		Inline buttons «№N Title» that open a tournament in private (callback popen:<id>).

		@param list<Tournament> $tournaments
		@param array<int, string> $groups
		@return array<string, mixed>
	*/
	public static function openButtons(array $tournaments, array $groups): array {
		$rows = [];
		foreach ($tournaments as $t) {
			$label = '№' . (int) $t->number . ' ' . $t->title;
			if (count($groups) > 1) {
				$label .= ' · ' . ($groups[$t->chat_id] ?? '');
			}
			$rows[] = [['text' => mb_substr($label, 0, 60), 'callback_data' => 'popen:' . $t->id]];
		}
		return $rows === [] ? [] : ['reply_markup' => ['inline_keyboard' => $rows]];
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
