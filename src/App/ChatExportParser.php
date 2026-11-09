<?php

declare(strict_types=1);

namespace P1Bot\App;

use InvalidArgumentException;

/**
	Parses a Telegram Desktop chat export (JSON, machine-readable) for member user ids.
*/
class ChatExportParser {

	/**
		@param array<string, mixed> $export decoded result.json
		@return array{
			export_id: int,
			type: string,
			name: string,
			chat_id: int,
			user_ids: list<int>
		}
	*/
	public function parse(array $export): array {
		if (!isset($export['id'])) {
			throw new InvalidArgumentException('В экспорте нет поля id (это не result.json чата?).');
		}
		$type = (string) ($export['type'] ?? '');
		$name = (string) ($export['name'] ?? '');
		$export_id = (int) $export['id'];
		$chat_id = $this->deriveChatId($export_id, $type);
		$messages = $export['messages'] ?? null;
		if (!is_array($messages)) {
			throw new InvalidArgumentException('В экспорте нет массива messages.');
		}
		$ids = $this->collectUserIds($messages);
		sort($ids);
		return [
			'export_id' => $export_id,
			'type' => $type,
			'name' => $name,
			'chat_id' => $chat_id,
			'user_ids' => array_values($ids),
		];
	}

	/**
		Supergroup/channel: -100{id}. Basic group: -{id}.
		If the export already stores a negative id, keep it.
	*/
	public function deriveChatId(int $export_id, string $type): int {
		if ($export_id < 0) {
			return $export_id;
		}
		$t = mb_strtolower($type);
		if (str_contains($t, 'supergroup') || str_contains($t, 'channel')) {
			return (int) ('-100' . $export_id);
		}
		// private_group / group / …
		return -1 * $export_id;
	}

	/**
		@param list<mixed> $messages
		@return list<int>
	*/
	public function collectUserIds(array $messages): array {
		$seen = [];
		foreach ($messages as $message) {
			if (!is_array($message)) {
				continue;
			}
			foreach ($this->idsFromMessage($message) as $id) {
				$seen[$id] = true;
			}
		}
		return array_map('intval', array_keys($seen));
	}

	/**
		@param array<string, mixed> $message
		@return list<int>
	*/
	protected function idsFromMessage(array $message): array {
		$ids = [];
		foreach (['from_id', 'actor_id'] as $key) {
			if (!isset($message[$key])) {
				continue;
			}
			$id = $this->parsePeerId((string) $message[$key]);
			if ($id !== null) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
		"user123" → 123; "channel456" / other peers → null.
	*/
	public function parsePeerId(string $peer): ?int {
		$peer = trim($peer);
		if (preg_match('/^user(\d+)$/', $peer, $m)) {
			return (int) $m[1];
		}
		return null;
	}
}
