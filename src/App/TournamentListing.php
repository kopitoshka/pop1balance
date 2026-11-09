<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\View\DeepLink;

use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\MoscowTime;
use P1Bot\Domain\TournamentService;
use P1Bot\Domain\TournamentStatus;
use P1Bot\View\AnnouncementView;
use P1Bot\View\Html;

/**
	The pretty tournament list for /tournaments and the AI: one block per tournament,
	packed into messages under Telegram's 4096 characters. Tournaments are global.
*/
class TournamentListing {
	protected const TITLE = '📋 <b>Турниры</b>';
	protected const SEPARATOR = "\n\n➖➖➖➖➖\n\n";


	protected const HISTORY_TITLE = '📜 <b>Прошедшие турниры</b>';
	protected const HISTORY_LIMIT = 10;

	protected const PRIVATE_DRAFT = '<i>Черновик, его видишь только ты. Опубликовать: /open %d</i>';

	public function __construct(
		protected TournamentService $tournaments,
		protected Announcer $announcer,
	) {
	}

	/**
		@param bool $with_drafts also include unpublished drafts (filtered by caller via privateMessages)
		@return list<string> HTML messages; empty when there is nothing to show
	*/
	public function messages(int $chat_id, bool $with_drafts, int $limit = AnnouncementView::MESSAGE_LIMIT): array {
		unset($chat_id);
		$block_limit = $limit - mb_strlen(self::TITLE) - mb_strlen(self::SEPARATOR);
		$blocks = [];
		foreach ($this->tournaments->listAllActive() as $t) {
			$blocks[] = $this->announcer->listBlock($t, $block_limit);
		}
		if ($with_drafts) {
			foreach ($this->tournaments->listAllByStatus([TournamentStatus::DRAFT]) as $t) {
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
		Published tournaments (global) plus drafts of this player (all drafts for the owner).

		@param array<int, string> $groups unused for filtering; kept for button labels
		@return list<string> HTML messages; empty when there is nothing to show
	*/
	public function privateMessages(
		array $groups,
		int $player_id,
		bool $is_owner,
		int $limit = AnnouncementView::MESSAGE_LIMIT,
	): array {
		unset($groups);
		$block_limit = $limit - mb_strlen(self::TITLE) - mb_strlen(self::SEPARATOR);
		$blocks = [];
		foreach ($this->privateTournaments([], $player_id, $is_owner) as $t) {
			$footer = $t->status === TournamentStatus::DRAFT ? sprintf(self::PRIVATE_DRAFT, (int) $t->number) : '';
			$blocks[] = $this->announcer->listBlock($t, $block_limit, $footer);
		}
		return $this->pack($blocks, $limit);
	}

	/**
		@param array<int, string> $groups unused (tournaments are global)
		@return list<Tournament>
	*/
	public function privateTournaments(array $groups, int $player_id, bool $is_owner): array {
		unset($groups);
		$result = [];
		foreach ($this->tournaments->listAllActive() as $t) {
			$result[] = $this->announcer->number($t);
		}
		foreach ($this->tournaments->listAllByStatus([TournamentStatus::DRAFT]) as $t) {
			if ($is_owner || $t->organizer_id === $player_id) {
				$result[] = $this->announcer->number($t);
			}
		}
		return $result;
	}

	/**
		Recent finished/cancelled tournaments, newest first. Number (= id) stays so /info N works.

		@return list<string> HTML messages; empty when there is nothing to show
	*/
	public function historyMessages(int $limit = self::HISTORY_LIMIT): array {
		$blocks = [];
		foreach ($this->tournaments->listRecentByStatus(TournamentStatus::past(), $limit) as $t) {
			$lineup = $this->tournaments->lineup($t->id);
			$teams = count($lineup->main_teams) + count($lineup->reserve_teams);
			$players = $lineup->occupied() + $lineup->reserveCount();
			$blocks[] = sprintf(
				'№%d %s — %s, команды по %d, команд %d / игроков %d, %s',
				$t->id,
				Html::b($t->title),
				MoscowTime::format($t->starts_at),
				$t->team_size,
				$teams,
				$players,
				TournamentStatus::label($t->status),
			);
		}
		if ($blocks === []) {
			return [];
		}
		$hint = "\n\n<i>Состав: /info N</i>";
		$messages = [];
		$current = self::HISTORY_TITLE;
		foreach ($blocks as $block) {
			$piece = ($current === self::HISTORY_TITLE ? "\n\n" : "\n") . $block;
			if (mb_strlen($current . $piece . $hint) > AnnouncementView::MESSAGE_LIMIT) {
				$messages[] = $current . $hint;
				$current = $block;
				continue;
			}
			$current .= $piece;
		}
		$messages[] = $current . $hint;
		return $messages;
	}

	/**
		Inline buttons «№N Title» that open a tournament in private (callback popen:<id>).

		@param list<Tournament> $tournaments
		@param array<int, string> $groups
		@return array<string, mixed>
	*/

	
	/**
		Group list: URL buttons into the private tournament view (popen does not work in groups).

		@param list<Tournament> $tournaments
		@return array<string, mixed>
	*/
	public static function deepLinkButtons(array $tournaments, string $bot_username): array {
		$rows = [];
		foreach ($tournaments as $t) {
			$label = '№' . (int) $t->number . ' ' . $t->title;
			$rows[] = [[
				'text' => mb_substr($label, 0, 60),
				'url' => DeepLink::tournament($bot_username, $t->id),
			]];
		}
		return $rows === [] ? [] : ['reply_markup' => ['inline_keyboard' => $rows]];
	}

	public static function openButtons(array $tournaments, array $groups): array {
		unset($groups);
		$rows = [];
		foreach ($tournaments as $t) {
			$label = '№' . (int) $t->number . ' ' . $t->title;
			$rows[] = [['text' => mb_substr($label, 0, 60), 'callback_data' => 'popen:' . $t->id]];
		}
		return $rows === [] ? [] : ['reply_markup' => ['inline_keyboard' => $rows]];
	}

	/**
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
