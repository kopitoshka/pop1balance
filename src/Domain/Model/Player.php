<?php

declare(strict_types=1);

namespace P1Bot\Domain\Model;

use P1Bot\View\Html;

class Player {
	public const DELETED_LABEL = 'удалённый игрок';

	public function __construct(
		public int $id,
		public ?int $tg_user_id,
		public ?string $username,
		public string $display_name,
		public ?string $ign = null,
		public ?string $ign_norm = null,
		public ?string $deleted_at = null,
		public bool $can_su = false,
		public ?bool $su_mode = null,
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
			isset($row['deleted_at']) && $row['deleted_at'] !== '' ? (string) $row['deleted_at'] : null,
			!empty($row['can_su']),
			isset($row['su_mode']) && $row['su_mode'] !== '' ? (bool) $row['su_mode'] : null,
		);
	}

	/**
		Soft-deleted by the owner: the row stays for history only.
	*/
	public function isDeleted(): bool {
		return $this->deleted_at !== null;
	}

	/**
		"@vasya" if known, otherwise the display name.
	*/
	public function mention(): string {
		if ($this->isDeleted()) {
			return self::DELETED_LABEL;
		}
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
		return $this->link(Html::e($this->name()));
	}

	/**
		The one name a player goes by in lists: game nick, else @username, else the Telegram name.
		Plain text (not escaped).
	*/
	public function name(): string {
		if ($this->isDeleted()) {
			return self::DELETED_LABEL;
		}
		if ($this->hasIgn()) {
			return (string) $this->ign;
		}
		if ($this->username !== null && $this->username !== '') {
			return '@' . $this->username;
		}
		return $this->display_name !== '' ? $this->display_name : 'игрок #' . $this->id;
	}

	/**
		Plain text for /level and /nick: «IGN (@user)» or mention.
	*/
	public function plainLabel(): string {
		if ($this->isDeleted()) {
			return self::DELETED_LABEL;
		}
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
