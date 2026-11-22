<?php

declare(strict_types=1);

namespace P1Bot\Support;

use P1Bot\Domain\Exception\ValidationError;

/**
	Files of the uploaded pictures in one directory (public/media). Knows nothing about tournaments.

	save() takes the raw bytes of an upload, checks them (JPEG, PNG or WebP, size, pixels) and writes a
	clean copy under a random name: with GD the picture is decoded, turned upright by its EXIF
	orientation, scaled down to MAX_WIDTH x MAX_HEIGHT, flattened on white and written again as JPEG, so
	no metadata (GPS, camera, thumbnails) survives. Without GD the original bytes are stored as they are
	(still only a valid JPEG, PNG or WebP) and the metadata stays.

	A file name is 32 random hex chars and an extension: it says nothing about the tournament.
*/
class ImageStore {
	public const MAX_WIDTH = 1280;
	public const MAX_HEIGHT = 1920;
	public const MAX_PIXELS = 25000000;
	public const JPEG_QUALITY = 85;
	public const NAME_PATTERN = '/^[a-f0-9]{32}\.(jpg|png|webp)$/D';

	public const TYPES_TEXT = 'Подойдут только картинки JPEG, PNG или WebP.';
	public const UNREADABLE_TEXT = 'Не получилось прочитать картинку. Попробуй другой файл.';

	public function __construct(protected string $dir, protected int $max_bytes) {
	}

	public function maxBytes(): int {
		return $this->max_bytes;
	}

	public function dir(): string {
		return $this->dir;
	}

	public static function isName(?string $name): bool {
		return $name !== null && preg_match(self::NAME_PATTERN, $name) === 1;
	}

	public function path(string $name): string {
		return rtrim($this->dir, '/') . '/' . $name;
	}

	public function exists(?string $name): bool {
		return self::isName($name) && is_file($this->path((string) $name));
	}

	/**
		Checks and stores an upload.

		@return string the stored file name
		@throws ValidationError with a text for the user
	*/
	public function save(string $bytes): string {
		if ($bytes === '') {
			throw new ValidationError(self::UNREADABLE_TEXT);
		}
		if (strlen($bytes) > $this->max_bytes) {
			throw new ValidationError(sprintf(
				'Картинка тяжелее %s. Выбери файл поменьше.',
				self::megabytes($this->max_bytes),
			));
		}
		$info = @getimagesizefromstring($bytes);
		if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
			throw new ValidationError(self::TYPES_TEXT);
		}
		$width = (int) $info[0];
		$height = (int) $info[1];
		if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
			throw new ValidationError(sprintf(
				'Слишком большая картинка: не больше %d мегапикселей.',
				intdiv(self::MAX_PIXELS, 1000000),
			));
		}
		if (!extension_loaded('gd')) {
			return $this->write($bytes, self::extension((int) $info[2]));
		}
		return $this->write($this->reencode($bytes, (int) $info[2], $width, $height), 'jpg');
	}

	/**
		Removes a stored file. Only names made by save() are touched; anything else is ignored.
	*/
	public function delete(?string $name): void {
		if (self::isName($name) && is_file($this->path((string) $name))) {
			@unlink($this->path((string) $name));
		}
	}

	/**
		Deletes files nobody uses: not in $keep and not touched for $min_age seconds (uploads of a form
		that was never saved). Other files in the directory are left alone.

		@param list<string> $keep file names in use
		@return int number of files deleted
	*/
	public function sweep(array $keep, int $min_age, int $now): int {
		$deleted = 0;
		$keep = array_flip($keep);
		foreach (glob(rtrim($this->dir, '/') . '/*') ?: [] as $file) {
			$name = basename($file);
			if (!self::isName($name) || isset($keep[$name]) || !is_file($file)) {
				continue;
			}
			$mtime = filemtime($file);
			if ($mtime !== false && $now - $mtime >= $min_age && @unlink($file)) {
				$deleted++;
			}
		}
		return $deleted;
	}

	public static function megabytes(int $bytes): string {
		$mb = $bytes / 1048576.0;
		return (floor($mb) === $mb ? (string) (int) $mb : number_format($mb, 1, ',', '')) . ' МБ';
	}

	protected static function extension(int $type): string {
		return match ($type) {
			IMAGETYPE_PNG => 'png',
			IMAGETYPE_WEBP => 'webp',
			default => 'jpg',
		};
	}

	protected function reencode(string $bytes, int $type, int $width, int $height): string {
		$source = @imagecreatefromstring($bytes);
		if ($source === false) {
			throw new ValidationError(self::UNREADABLE_TEXT);
		}
		if ($type === IMAGETYPE_JPEG) {
			$angle = self::exifAngle($bytes);
			if ($angle !== 0) {
				$rotated = imagerotate($source, $angle, 0);
				if ($rotated !== false) {
					$source = $rotated;
					$width = imagesx($source);
					$height = imagesy($source);
				}
			}
		}
		$ratio = min(1.0, self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height);
		$new_width = max(1, (int) round($width * $ratio));
		$new_height = max(1, (int) round($height * $ratio));
		$canvas = imagecreatetruecolor($new_width, $new_height);
		imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
		imagecopyresampled($canvas, $source, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
		imageinterlace($canvas, true);
		ob_start();
		imagejpeg($canvas, null, self::JPEG_QUALITY);
		$jpeg = (string) ob_get_clean();
		if ($jpeg === '') {
			throw new ValidationError(self::UNREADABLE_TEXT);
		}
		return $jpeg;
	}

	/**
		Counter-clockwise angle that makes a JPEG upright, from its EXIF Orientation (0 = leave as is).
	*/
	protected static function exifAngle(string $bytes): int {
		if (!function_exists('exif_read_data')) {
			return 0;
		}
		$exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
		$orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
		return [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
	}

	protected function write(string $bytes, string $extension): string {
		if (!is_dir($this->dir)) {
			@mkdir($this->dir, 0775, true);
		}
		$name = bin2hex(random_bytes(16)) . '.' . $extension;
		if (@file_put_contents($this->path($name), $bytes, LOCK_EX) === false) {
			throw new ValidationError('Не получилось сохранить картинку. Попробуй ещё раз чуть позже.');
		}
		@chmod($this->path($name), 0644);
		return $name;
	}
}
