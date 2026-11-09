<?php

declare(strict_types=1);

namespace P1Bot\App;

use InvalidArgumentException;
use RuntimeException;

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
		Stream a large result.json: metadata from the header, user ids via regex over chunks.
		Does not load the whole file into memory.

		@return array{
			export_id: int,
			type: string,
			name: string,
			chat_id: int,
			user_ids: list<int>
		}
	*/
	public function parseFile(string $path): array {
		$fh = fopen($path, 'rb');
		if ($fh === false) {
			throw new RuntimeException('Не удалось открыть файл: ' . $path);
		}
		try {
			$header = $this->readUntilMessages($fh);
			$meta = $this->parseHeader($header);
			$ids = $this->scanUserIds($fh);
		} finally {
			fclose($fh);
		}
		$keys = array_keys($ids);
		sort($keys);
		$export_id = $meta['export_id'];
		$type = $meta['type'];
		return [
			'export_id' => $export_id,
			'type' => $type,
			'name' => $meta['name'],
			'chat_id' => $this->deriveChatId($export_id, $type),
			'user_ids' => array_map('intval', $keys),
		];
	}

	/**
		Supergroup/channel: -100{id}. Basic group: -{id}.
		If the export already stores a negative id, keep it.
		private_supergroup / public_supergroup both use the -100 prefix (Bot API chat id).
	*/
	public function deriveChatId(int $export_id, string $type): int {
		if ($export_id < 0) {
			return $export_id;
		}
		$t = mb_strtolower($type);
		if (str_contains($t, 'supergroup') || str_contains($t, 'channel')) {
			// Concatenate so large ids stay exact (e.g. 2119660082 → -1002119660082).
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

	/**
		Read from the start until the "messages" key (bytes before that key).
		@param resource $fh
	*/
	protected function readUntilMessages($fh): string {
		$buf = '';
		while (!feof($fh)) {
			$chunk = fread($fh, 8192);
			if ($chunk === false || $chunk === '') {
				break;
			}
			$buf .= $chunk;
			if (preg_match('/"messages"\s*:/', $buf, $m, PREG_OFFSET_CAPTURE)) {
				return substr($buf, 0, (int) $m[0][1]);
			}
			if (strlen($buf) > 256 * 1024) {
				throw new InvalidArgumentException(
					'В начале экспорта нет поля messages (это не result.json чата?).',
				);
			}
		}
		throw new InvalidArgumentException('В экспорте нет массива messages.');
	}

	/**
		@param string $header bytes before "messages"
		@return array{export_id: int, type: string, name: string}
	*/
	protected function parseHeader(string $header): array {
		$json = rtrim($header);
		$json = rtrim($json, ", \t\r\n");
		$json .= '}';
		$data = json_decode($json, true);
		if (is_array($data) && isset($data['id'])) {
			return [
				'export_id' => (int) $data['id'],
				'type' => (string) ($data['type'] ?? ''),
				'name' => (string) ($data['name'] ?? ''),
			];
		}
		if (!preg_match('/"id"\s*:\s*(-?\d+)/', $header, $id_m)) {
			throw new InvalidArgumentException('В экспорте нет поля id (это не result.json чата?).');
		}
		$type = '';
		$name = '';
		if (preg_match('/"type"\s*:\s*"([^"]*)"/', $header, $t_m)) {
			$type = $t_m[1];
		}
		if (preg_match('/"name"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/', $header, $n_m)) {
			$name = stripcslashes($n_m[1]);
		}
		return [
			'export_id' => (int) $id_m[1],
			'type' => $type,
			'name' => $name,
		];
	}

	/**
		Full-file regex scan for "from_id"/"actor_id": "userNNNN". Constant memory.
		@param resource $fh
		@return array<int, true>
	*/
	protected function scanUserIds($fh): array {
		rewind($fh);
		$seen = [];
		$carry = '';
		$pattern = '/"(?:from_id|actor_id)"\s*:\s*"user(\d+)"/';
		while (!feof($fh)) {
			$chunk = fread($fh, 1024 * 1024);
			if ($chunk === false || $chunk === '') {
				break;
			}
			$text = $carry . $chunk;
			if (preg_match_all($pattern, $text, $m)) {
				foreach ($m[1] as $id) {
					$seen[(int) $id] = true;
				}
			}
			$carry = substr($text, -64);
		}
		return $seen;
	}
}
