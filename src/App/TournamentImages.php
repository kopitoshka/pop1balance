<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Domain\Model\Tournament;
use P1Bot\Repository\TournamentRepository;
use P1Bot\Support\ImageStore;

/**
	Tournament pictures: the on/off switch, the public URL and the link preview that shows a picture
	above a one-tournament message.

	A picture is shown with link_preview_options (url = the picture, large, above the text) on a message
	that has no link in its text. Telegram caches a preview per URL, so every upload gets a new random
	file name. With the switch off (TOURNAMENT_IMAGES_ENABLED=0) the form has no picture field, no
	preview is sent and uploads are refused; files and the image_path column stay where they are.
	The switch is also off when no public URL can be built (no MEDIA_BASE_URL and no https WEBHOOK_URL).
*/
class TournamentImages {
	public const DISABLED_TEXT = 'Картинки турниров сейчас отключены.';
	public const STALE_TEXT = 'Загрузка картинки устарела: выбери картинку ещё раз.';

	/**
		A picture nobody saved is deleted after this long (the form context lives 6 hours).
	*/
	public const ORPHAN_AGE_SECONDS = 86400;

	public function __construct(
		protected ImageStore $store,
		protected TournamentRepository $tournaments,
		protected bool $enabled,
		protected ?string $base_url,
	) {
	}

	public function enabled(): bool {
		return $this->enabled && $this->base_url !== null;
	}

	public function store(): ImageStore {
		return $this->store;
	}

	/**
		What the Mini App forms need to know.

		@return array{enabled: bool, max_bytes: int, max_text: string, max_width: int}
	*/
	public function settings(): array {
		return [
			'enabled' => $this->enabled(),
			'max_bytes' => $this->store->maxBytes(),
			'max_text' => ImageStore::megabytes($this->store->maxBytes()),
			'max_width' => ImageStore::MAX_WIDTH,
		];
	}

	/**
		Public URL of a stored file, or null.
	*/
	public function url(?string $name): ?string {
		if ($this->base_url === null || !ImageStore::isName($name)) {
			return null;
		}
		return $this->base_url . '/' . $name;
	}

	/**
		sendMessage / editMessageText options for a message about ONE tournament: the picture as a
		large preview above the text. Empty without a picture or with the switch off (the message then
		keeps the default: no preview).

		@return array<string, mixed>
	*/
	public function previewOptions(Tournament $t): array {
		if (!$this->enabled()) {
			return [];
		}
		$url = $this->url($t->image_path);
		if ($url === null) {
			return [];
		}
		return ['link_preview_options' => [
			'url' => $url,
			'prefer_large_media' => true,
			'show_above_text' => true,
		]];
	}

	/**
		Stores an upload.

		@return string the stored file name
	*/
	public function save(string $bytes): string {
		if (!$this->enabled()) {
			throw new \P1Bot\Domain\Exception\ValidationError(self::DISABLED_TEXT);
		}
		return $this->store->save($bytes);
	}

	/**
		Deletes a file even with the switch off (a deleted tournament must not leave its picture).
	*/
	public function discard(?string $name): void {
		$this->store->delete($name);
	}

	/**
		Cron: deletes pictures that belong to no tournament and are older than a day.

		@return int files deleted
	*/
	public function sweep(int $now): int {
		return $this->store->sweep($this->tournaments->imagePaths(), self::ORPHAN_AGE_SECONDS, $now);
	}
}
