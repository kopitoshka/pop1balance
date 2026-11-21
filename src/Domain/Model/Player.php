<?php

declare(strict_types=1);

namespace P1Bot\Domain\Model;

use P1Bot\View\Html;

class Player {

	public function __construct(
		public int $id,
		public ?int $tg_user_id,
		public ?string $username,
		public string $display_name,
		public ?string $ign = null,
		public ?string $ign_norm = null,
	) {
	}

	/**
		@param array<string, mixed> $row
	*/
	public static function fromRow(array $row): self {
		return new self(
			(int) $row['id'],
			$row['tg_user_id'] === null ? null : (int) $row['tg_user_id'],
			$row['username'] === null ? null : (string) $row['username'],
			(string) $row['display_name'],
			isset($row['ign']) && $row['ign'] !== null && $row['ign'] !== '' ? (string) $row['ign'] : null,
			isset($row['ign_norm']) && $row['ign_norm'] !== null && $row['ign_norm'] !== ''
				? (string) $row['ign_norm']
				: null,
		);
	}

	/**
		"@vasya" if known, otherwise the display name.
	*/
	public function mention(): string {
		if ($this->username !== null && $this->username !== '') {
			return '@' . $this->username;
		}
		return $this->display_name !== '' ? $this->display_name : 'игрок #' . $this->id;
	}

	public function hasIgn(): bool {
		return $this->ign !== null && $this->ign !== '';
	}

	/**
		Known to Telegram (has a tg id): can get messages and open forms.
		Players added by @username or by nick only (/addplayer) have none until they write to the bot.
		They can still be made captain by the tournament organizer or the bot owner.
	*/
	public function hasTelegram(): bool {
		return $this->tg_user_id !== null;
	}

	/**
		Wraps already-escaped HTML in a profile link: https://t.me/<username> when known
		(Telegram always highlights those), else tg://user?id=, else returns it as plain text.
	*/
	public function link(string $label_html): string {
		if ($this->username !== null && $this->username !== '') {
			return '<a href="https://t.me/' . rawurlencode($this->username) . '">' . $label_html . '</a>';
		}
		if ($this->tg_user_id !== null) {
			return '<a href="tg://user?id=' . $this->tg_user_id . '">' . $label_html . '</a>';
		}
		return $label_html;
	}

	/**
		HTML for lineups/cards: IGN / @username / name as a profile link when possible (see link()).
		tg://user?id= clients often show as plain text unless the user shared a private link or
		started the bot (Telegram privacy). Players without Telegram stay plain text.
		Already escaped — do not Html::e again.
	*/
	public function lineupLabel(): string {
		if ($this->hasIgn()) {
			$label = Html::e((string) $this->ign);
		} elseif ($this->username !== null && $this->username !== '') {
			$label = Html::e('@' . $this->username);
		} else {
			$label = Html::e($this->display_name !== '' ? $this->display_name : 'игрок #' . $this->id);
		}
		return $this->link($label);
	}

	/**
		Plain text for /level and /nick: «IGN (@user)» or mention.
	*/
	public function plainLabel(): string {
		if ($this->hasIgn()) {
			$ign = (string) $this->ign;
			if ($this->username !== null && $this->username !== '') {
				return $ign . ' (@' . $this->username . ')';
			}
			return $ign;
		}
		return $this->mention();
	}
}
