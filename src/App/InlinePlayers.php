<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Domain\BalanceService;
use P1Bot\Domain\Levels;
use P1Bot\Domain\Model\Player;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Telegram\TelegramApi;
use P1Bot\Telegram\TgUser;

/**
	Inline mode: type @bot nick… to pick a known player; inserts @username (or name).
*/
class InlinePlayers {

	public function __construct(
		protected TelegramApi $api,
		protected PlayerRepository $players,
		protected BalanceService $balances,
	) {
	}

	/**
		@param array<string, mixed> $inline_query raw InlineQuery
	*/
	public function handle(array $inline_query): void {
		$id = (string) ($inline_query['id'] ?? '');
		if ($id === '') {
			return;
		}
		$query = trim((string) ($inline_query['query'] ?? ''));
		$found = $this->players->searchKnown($query, 20);
		$results = [];
		foreach ($found as $i => $player) {
			$results[] = $this->article($player, $i);
		}
		$this->api->answerInlineQuery($id, $results, [
			'cache_time' => 10,
			'is_personal' => true,
		]);
	}

	/**
		@param array<string, mixed> $inline_query
	*/
	public function describe(array $inline_query): string {
		$q = trim((string) ($inline_query['query'] ?? ''));
		$from = $inline_query['from']['id'] ?? '?';
		return 'inline_query from ' . $from . ' q=' . ($q !== '' ? $q : '(empty)');
	}

	/**
		@return array<string, mixed>
	*/
	protected function article(Player $player, int $index): array {
		$title = $player->username !== null && $player->username !== ''
			? '@' . $player->username
			: ($player->display_name !== '' ? $player->display_name : 'игрок #' . $player->id);
		$insert = $player->username !== null && $player->username !== ''
			? '@' . $player->username
			: $player->display_name;
		if ($insert === '') {
			$insert = $title;
		}
		$bits = [];
		if ($player->hasIgn()) {
			$bits[] = 'ник ' . $player->ign;
		}
		$level = $this->balances->levelOf($this->balances->viewScaleId(), $player->id);
		$bits[] = Levels::name($level) . ' (' . $level . ')';
		if ($player->display_name !== '' && ($player->username === null || strcasecmp($player->display_name, $player->username) !== 0)) {
			array_unshift($bits, $player->display_name);
		}
		$description = implode(' · ', $bits);
		return [
			'type' => 'article',
			'id' => 'p' . $player->id . '_' . $index,
			'title' => $title,
			'description' => $description,
			'input_message_content' => [
				'message_text' => $insert,
			],
		];
	}
}
