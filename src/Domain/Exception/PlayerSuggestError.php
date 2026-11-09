<?php

declare(strict_types=1);

namespace P1Bot\Domain\Exception;

use P1Bot\Domain\Model\Player;

/**
	@username not found, but similar known players exist — show pick buttons.
*/
class PlayerSuggestError extends ValidationError {

	/**
		@param list<Player> $suggestions
	*/
	public function __construct(
		string $message,
		public array $suggestions,
		public ?int $pending_id = null,
	) {
		parent::__construct($message);
	}

	/**
		Inline keyboard for «Может, ты про…».
		@return array<string, mixed>
	*/
	public function replyMarkup(): array {
		if ($this->pending_id === null || $this->suggestions === []) {
			return [];
		}
		$rows = [];
		foreach ($this->suggestions as $player) {
			$label = $player->username !== null && $player->username !== ''
				? '@' . $player->username
				: $player->display_name;
			if ($player->hasIgn()) {
				$label .= ' · ' . $player->ign;
			}
			// Keep button text short for clients.
			$label = mb_strlen($label) > 40 ? mb_substr($label, 0, 37) . '…' : $label;
			$data = 'sug:' . $this->pending_id . ':' . $player->id;
			if (strlen($data) > 64) {
				continue;
			}
			$rows[] = [['text' => 'Может, ты про ' . $label . '?', 'callback_data' => $data]];
		}
		return $rows === [] ? [] : ['reply_markup' => ['inline_keyboard' => $rows]];
	}
}
