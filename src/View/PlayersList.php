<?php

declare(strict_types=1);

namespace P1Bot\View;

use P1Bot\App\PlayerForm;
use P1Bot\Domain\BalanceService;
use P1Bot\Domain\Levels;
use P1Bot\Domain\Model\Player;
use P1Bot\Repository\PlayerRepository;

/**
	The /players list (and the same list when the AI calls list_players):
	every known player, one name, grouped by level, level titles in <blockquote>.
*/
class PlayersList {

	public function __construct(
		protected PlayerRepository $players,
		protected BalanceService $balances,
		protected ?PlayerForm $player_form = null,
	) {
	}

	/**
		Message chunks ready to send (footer already on the last one).

		@param (callable(int): ?string)|null $edit_link player id → Mini App URL, or null for no pencils
		@return list<string>
	*/
	public function messages(bool $is_owner, bool $is_private, ?callable $edit_link = null): array {
		$scale = $this->balances->viewScaleId() !== null ? $this->balances->findDefault() : null;
		$ratings = $scale !== null ? $this->balances->effectiveRatings($scale->id) : [];
		$by_level = [6 => [], 5 => [], 4 => [], 3 => [], 2 => [], 1 => [], 0 => []];
		$total = 0;
		foreach ($this->players->listAllKnown() as $player) {
			$level = isset($ratings[$player->id]) ? (int) $ratings[$player->id]['level'] : null;
			$by_level[$level !== null && isset($by_level[$level]) ? $level : 0][] = $player;
			$total++;
		}
		if ($total === 0) {
			return ['Пока я не знаю ни одного игрока 🙂'];
		}
		$edit = $edit_link !== null && $is_owner && $is_private;
		$lines = ['📋 <b>Игроки</b> — ' . $total];
		foreach ($by_level as $level => $list) {
			if ($list === []) {
				continue;
			}
			usort($list, static fn (Player $a, Player $b): int => strcmp(
				mb_strtolower(self::sortKey($a)),
				mb_strtolower(self::sortKey($b)),
			));
			$title = $level === 0
				? sprintf('Уровень не ставили — считаю %s (%d)', Levels::name(Levels::DEFAULT), Levels::DEFAULT)
				: sprintf('%s (%d)', Levels::name($level), $level);
			$lines[] = '';
			$lines[] = '<blockquote><b>' . Html::e($title) . '</b> — ' . count($list) . '</blockquote>';
			foreach ($list as $player) {
				$url = $edit ? $edit_link($player->id) : null;
				$lines[] = self::playerLine($player, $url);
			}
		}
		$footer = "\n\nКак устроены уровни — /help_level";
		if ($edit) {
			$footer .= "\n✏️ — карточка игрока: ник, уровень, @username, объединить дубли, удалить";
		}
		if ($is_owner) {
			$footer .= "\nПоставить уровень: /level @ник 4, ник: /nick @ник НикВИгре";
		}
		$chunks = self::split(implode("\n", $lines));
		$last = (string) array_pop($chunks);
		$chunks[] = $last . $footer;
		return $chunks;
	}

	/**
		✏️ links only for the owner in a private chat, when the Mini App link can be built.

		@return (callable(int): ?string)|null
	*/
	public function editLinkCallback(bool $is_owner, bool $is_private): ?callable {
		if (!$is_owner || !$is_private || $this->player_form === null || !$this->player_form->canLink()) {
			return null;
		}
		$form = $this->player_form;
		return static function (int $id) use ($form): ?string {
			return $form->editLink($id);
		};
	}

	public static function sortKey(Player $player): string {
		return $player->hasIgn() ? (string) $player->ign : ($player->display_name !== '' ? $player->display_name : (string) $player->username);
	}

	/**
		One list line: the name (nick, else @username, else the Telegram name; a link
		to the Telegram profile when there is one), then the ✏️ link for the owner.
	*/
	public static function playerLine(Player $player, ?string $edit_url = null): string {
		$line = $player->lineupLabel();
		if ($edit_url !== null) {
			$line .= ' <a href="' . Html::e($edit_url) . '">✏️</a>';
		}
		return $line;
	}

	/**
		@return list<string>
	*/
	public static function split(string $text, int $limit = 3900): array {
		if (mb_strlen($text) <= $limit) {
			return [$text];
		}
		$parts = [];
		$buf = '';
		foreach (explode("\n", $text) as $line) {
			$candidate = $buf === '' ? $line : $buf . "\n" . $line;
			if (mb_strlen($candidate) > $limit && $buf !== '') {
				$parts[] = $buf;
				$buf = $line;
			} else {
				$buf = $candidate;
			}
		}
		if ($buf !== '') {
			$parts[] = $buf;
		}
		return $parts !== [] ? $parts : [$text];
	}
}
