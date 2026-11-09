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
		HTML for lineups/cards: IGN as tg:// link when possible, else @username / name.
		Already escaped — do not Html::e again.
	*/
	public function lineupLabel(): string {
		if ($this->hasIgn()) {
			$label = Html::e((string) $this->ign);
			if ($this->tg_user_id !== null) {
				return '<a href="tg://user?id=' . $this->tg_user_id . '">' . $label . '</a>';
			}
			return $label;
		}
		return Html::e($this->mention());
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
