<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\App\TournamentImages;
use P1Bot\App\WebAppFormError;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Support\ImageStore;
use P1Bot\Tests\Support\IntegrationTestCase;

/**
	Tournament pictures: the Mini App upload (access, validation, clean copy), attaching to a tournament,
	deleting files, the link preview above one-tournament messages and the on/off switch.
*/
class TournamentImageTest extends IntegrationTestCase {
	protected const BASE = 'https://pop1.example.com/media/';

	protected bool $images_on = true;
	protected int $max_bytes = 5242880;

	protected function setUp(): void {
		parent::setUp();
		if (!extension_loaded('gd')) {
			self::markTestSkipped('GD is needed to make test pictures');
		}
	}

	protected function config(): array {
		$c = parent::config();
		$c['telegram']['webhook_url'] = 'https://pop1.example.com/webhook.php';
		$c['media'] = ['tournament_images_enabled' => $this->images_on, 'base_url' => '', 'max_bytes' => $this->max_bytes];
		return $c;
	}

	protected function png(int $width = 40, int $height = 20): string {
		$im = imagecreatetruecolor($width, $height);
		imagefill($im, 0, 0, (int) imagecolorallocate($im, 200, 30, 30));
		ob_start();
		imagepng($im);
		return (string) ob_get_clean();
	}

	/**
		A JPEG with an APP1 (EXIF) segment: a secret comment and, when given, an Orientation tag.
	*/
	protected function jpegWithExif(int $width, int $height, int $orientation = 1): string {
		$im = imagecreatetruecolor($width, $height);
		imagefill($im, 0, 0, (int) imagecolorallocate($im, 20, 120, 200));
		ob_start();
		imagejpeg($im);
		$jpeg = (string) ob_get_clean();
		$tiff = 'MM' . "\x00\x2a" . pack('N', 8) . pack('n', 1) . pack('nnN', 0x0112, 3, 1) . pack('n', $orientation)
			. "\x00\x00" . pack('N', 0) . 'SECRET-GPS-55.75N';
		$segment = "\xFF\xE1" . pack('n', 2 + 6 + strlen($tiff)) . "Exif\x00\x00" . $tiff;
		return substr($jpeg, 0, 2) . $segment . substr($jpeg, 2);
	}

	/**
		A PNG header that declares a huge picture (nothing else needed to be refused).
	*/
	protected function hugePng(): string {
		$ihdr = pack('NNCCCCC', 6000, 5000, 8, 2, 0, 0, 0);
		return "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
	}

	/**
		@return array{error: int, tmp_name: string, size: int}
	*/
	protected function upload(string $bytes, int $error = UPLOAD_ERR_OK): array {
		$path = tempnam(sys_get_temp_dir(), 'p1up');
		file_put_contents($path, $bytes);
		return ['error' => $error, 'tmp_name' => $path, 'size' => strlen($bytes)];
	}

	protected function tokenFor(Player $who): string {
		$this->app->chats->ensure((int) $who->tg_user_id, $who->display_name);
		return (string) $this->app->create_form->issueToken($who);
	}

	protected function editToken(Player $who, int $id = 1): string {
		$this->app->chats->ensure((int) $who->tg_user_id, $who->display_name);
		$button = $this->app->create_form->editButton($this->app->tournaments->get($id), (int) $who->tg_user_id, $who->id);
		self::assertNotNull($button);
		parse_str((string) parse_url($button['web_app']['url'], PHP_URL_QUERY), $q);
		return $q['ctx'];
	}

	/**
		@return array{ok: true, url: string}
	*/
	protected function uploadAs(Player $who, string $token, string $bytes): array {
		return $this->app->create_form->imageUpload($this->initDataFor($who), $token, $this->upload($bytes));
	}

	protected function nameOf(string $url): string {
		self::assertStringStartsWith(self::BASE, $url);
		return substr($url, strlen(self::BASE));
	}

	/**
		@param array<string, mixed> $override
		@return array<string, mixed>
	*/
	protected function form(array $override = []): array {
		return $override + [
			'title' => 'Кубок', 'date' => '2026-11-23', 'time' => '20:07', 'team_size' => 3,
			'level_mode' => 'players', 'levels' => [3, 4, 5], 'max_players' => 24,
		];
	}

	/**
		A draft of the owner with a picture; returns the stored file name.
	*/
	protected function draftWithImage(): string {
		$token = $this->tokenFor($this->owner);
		$name = $this->nameOf($this->uploadAs($this->owner, $token, $this->png())['url']);
		self::assertNull($this->submitForm($this->owner, $this->form(['image' => 'new']), $token));
		return $name;
	}

	protected function publish(): void {
		$this->dispatch($this->press($this->owner, 'pub:1', (int) $this->app->tournament_repo->find(1)->card_msg_id));
		self::assertSame(TournamentStatus::REGISTRATION, $this->app->tournament_repo->find(1)->status);
	}

	protected function failure(callable $call): WebAppFormError {
		try {
			$call();
			self::fail('Expected a form error');
		} catch (WebAppFormError $e) {
			return $e;
		}
	}

	protected function mediaFiles(): array {
		return array_map('basename', glob($this->media_dir . '/*') ?: []);
	}

	// ---- upload: access ----

	public function testAuthorUploadsInCreateFormAndGetsPublicUrl(): void {
		$token = $this->tokenFor($this->owner);
		$result = $this->uploadAs($this->owner, $token, $this->png());
		self::assertTrue($result['ok']);
		$name = $this->nameOf($result['url']);
		self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.jpg$/', $name, 'random name, no ids');
		self::assertFileExists($this->media_dir . '/' . $name);
	}

	public function testStrangerCannotUseSomeoneElsesCreateContext(): void {
		$token = $this->tokenFor($this->owner);
		$stranger = $this->player('stranger');
		$e = $this->failure(fn () => $this->uploadAs($stranger, $token, $this->png()));
		self::assertSame(WebAppFormError::FORBIDDEN, $e->reason);
		self::assertSame([], $this->mediaFiles());
	}

	public function testPlayerWhoMayNotCreateIsRefusedEvenWithOwnContext(): void {
		$stranger = $this->player('stranger');
		$token = $this->tokenFor($stranger);
		$this->withoutSharedOwnerGroup($stranger);
		$e = $this->failure(fn () => $this->uploadAs($stranger, $token, $this->png()));
		self::assertSame(WebAppFormError::FORBIDDEN, $e->reason);
		self::assertSame([], $this->mediaFiles());
	}

	public function testUnknownTokenIsExpired(): void {
		$e = $this->failure(fn () => $this->uploadAs($this->owner, str_repeat('a', 32), $this->png()));
		self::assertSame(WebAppFormError::EXPIRED, $e->reason);
		$e = $this->failure(fn () => $this->uploadAs($this->owner, 'junk', $this->png()));
		self::assertSame(WebAppFormError::EXPIRED, $e->reason);
	}

	public function testEditContextOnlyForItsAuthorOrSuperadmin(): void {
		$this->newDraft($this->owner, '23.11 20:07 | Кубок | 3 | 3,4,5 | 24');
		$token = $this->editToken($this->owner);
		$stranger = $this->player('stranger');
		$e = $this->failure(fn () => $this->uploadAs($stranger, $token, $this->png()));
		self::assertSame(WebAppFormError::FORBIDDEN, $e->reason);
		self::assertSame([], $this->mediaFiles());
		// the owner is a superadmin and may edit any tournament, the author their own
		self::assertTrue($this->uploadAs($this->owner, $token, $this->png())['ok']);
	}

	public function testUploadWithoutAuthDataIsRefused(): void {
		$token = $this->tokenFor($this->owner);
		$this->expectException(\Throwable::class);
		$this->app->create_form->imageUpload('', $token, $this->upload($this->png()));
	}

	// ---- upload: validation and clean copy ----

	public function testBigPictureIsScaledToMaxWidthAndReencodedAsJpeg(): void {
		$token = $this->tokenFor($this->owner);
		$name = $this->nameOf($this->uploadAs($this->owner, $token, $this->png(2000, 1000))['url']);
		$info = getimagesize($this->media_dir . '/' . $name);
		self::assertSame(IMAGETYPE_JPEG, $info[2]);
		self::assertSame([1280, 640], [$info[0], $info[1]]);
	}

	public function testMetadataIsStripped(): void {
		$source = $this->jpegWithExif(60, 40);
		self::assertStringContainsString('SECRET-GPS', $source);
		$token = $this->tokenFor($this->owner);
		$name = $this->nameOf($this->uploadAs($this->owner, $token, $source)['url']);
		$stored = (string) file_get_contents($this->media_dir . '/' . $name);
		self::assertStringNotContainsString('SECRET-GPS', $stored);
		self::assertStringNotContainsString('Exif', $stored);
	}

	public function testExifOrientationIsAppliedBeforeStripping(): void {
		if (!function_exists('exif_read_data')) {
			self::markTestSkipped('exif extension is not loaded');
		}
		$token = $this->tokenFor($this->owner);
		$name = $this->nameOf($this->uploadAs($this->owner, $token, $this->jpegWithExif(100, 50, 6))['url']);
		$info = getimagesize($this->media_dir . '/' . $name);
		self::assertSame([50, 100], [$info[0], $info[1]], 'turned upright');
	}

	public function testOnlyJpegPngWebpAreAccepted(): void {
		$token = $this->tokenFor($this->owner);
		$im = imagecreatetruecolor(10, 10);
		ob_start();
		imagegif($im);
		$gif = (string) ob_get_clean();
		foreach ([$gif, 'just text', '<svg xmlns="http://www.w3.org/2000/svg"/>', '<?php echo 1;', ''] as $bytes) {
			$e = $this->failure(fn () => $this->uploadAs($this->owner, $token, $bytes));
			self::assertSame('image', $e->field);
		}
		self::assertSame([], $this->mediaFiles());
	}

	public function testBrokenPictureIsRefused(): void {
		$token = $this->tokenFor($this->owner);
		$png = $this->png();
		$e = $this->failure(fn () => $this->uploadAs($this->owner, $token, substr($png, 0, 40) . str_repeat("\0", 50)));
		self::assertSame('image', $e->field);
		self::assertSame([], $this->mediaFiles());
	}

	public function testTooManyPixelsIsRefused(): void {
		$token = $this->tokenFor($this->owner);
		$e = $this->failure(fn () => $this->uploadAs($this->owner, $token, $this->hugePng()));
		self::assertStringContainsString('мегапикс', $e->getMessage());
		self::assertSame([], $this->mediaFiles());
	}

	public function testTooHeavyFileIsRefused(): void {
		$this->max_bytes = 2048;
		$this->setUp();
		$token = $this->tokenFor($this->owner);
		$noise = imagecreatetruecolor(200, 200);
		for ($i = 0; $i < 4000; $i++) {
			imagesetpixel($noise, random_int(0, 199), random_int(0, 199), random_int(0, 0xFFFFFF));
		}
		ob_start();
		imagepng($noise);
		$bytes = (string) ob_get_clean();
		self::assertGreaterThan(2048, strlen($bytes));
		$e = $this->failure(fn () => $this->uploadAs($this->owner, $token, $bytes));
		self::assertSame('image', $e->field);
		self::assertStringContainsString('тяжелее', $e->getMessage());
		self::assertSame([], $this->mediaFiles());
	}

	public function testPhpUploadErrorsAreMapped(): void {
		$token = $this->tokenFor($this->owner);
		$e = $this->failure(fn () => $this->app->create_form->imageUpload($this->initDataFor($this->owner), $token, ['error' => UPLOAD_ERR_INI_SIZE]));
		self::assertSame('image', $e->field);
		$e = $this->failure(fn () => $this->app->create_form->imageUpload($this->initDataFor($this->owner), $token, ['error' => UPLOAD_ERR_NO_FILE]));
		self::assertSame('image', $e->field);
		$e = $this->failure(fn () => $this->app->create_form->imageUpload($this->initDataFor($this->owner), $token, ['error' => UPLOAD_ERR_PARTIAL]));
		self::assertSame('image', $e->field);
	}

	public function testSecondUploadReplacesThePendingOne(): void {
		$token = $this->tokenFor($this->owner);
		$first = $this->nameOf($this->uploadAs($this->owner, $token, $this->png())['url']);
		$second = $this->nameOf($this->uploadAs($this->owner, $token, $this->png())['url']);
		self::assertNotSame($first, $second, 'a new name per upload: Telegram caches previews by URL');
		self::assertSame([$second], $this->mediaFiles());
	}

	// ---- attaching, replacing, removing ----

	public function testCreateWithPictureAttachesIt(): void {
		$name = $this->draftWithImage();
		self::assertSame($name, $this->app->tournament_repo->find(1)->image_path);
		self::assertSame([$name], $this->mediaFiles());
	}

	public function testCreateWithoutChoosingPictureDeletesTheUpload(): void {
		$token = $this->tokenFor($this->owner);
		$this->uploadAs($this->owner, $token, $this->png());
		self::assertNull($this->submitForm($this->owner, $this->form(), $token));
		self::assertNull($this->app->tournament_repo->find(1)->image_path);
		self::assertSame([], $this->mediaFiles());
	}

	public function testNewWithoutUploadIsAFormErrorAndCreatesNothing(): void {
		$message = $this->submitForm($this->owner, $this->form(['image' => 'new']));
		self::assertSame(TournamentImages::STALE_TEXT, $message);
		self::assertNull($this->app->tournament_repo->find(1));
	}

	public function testEditReplacesPictureAndDeletesTheOldFile(): void {
		$old = $this->draftWithImage();
		$token = $this->editToken($this->owner);
		$new = $this->nameOf($this->uploadAs($this->owner, $token, $this->png(30, 30))['url']);
		$this->app->create_form->editSubmit($this->initDataFor($this->owner), $token, $this->form(['image' => 'new']));
		self::assertSame($new, $this->app->tournament_repo->find(1)->image_path);
		self::assertSame([$new], $this->mediaFiles());
		self::assertNotSame($old, $new);
	}

	public function testEditKeepsPictureByDefault(): void {
		$name = $this->draftWithImage();
		$token = $this->editToken($this->owner);
		$this->app->create_form->editSubmit($this->initDataFor($this->owner), $token, $this->form(['title' => 'Другой']));
		self::assertSame($name, $this->app->tournament_repo->find(1)->image_path);
		self::assertSame([$name], $this->mediaFiles());
	}

	public function testEditRemovesPictureAndDeletesTheFile(): void {
		$this->draftWithImage();
		$token = $this->editToken($this->owner);
		$this->app->create_form->editSubmit($this->initDataFor($this->owner), $token, $this->form(['image' => 'remove']));
		self::assertNull($this->app->tournament_repo->find(1)->image_path);
		self::assertSame([], $this->mediaFiles());
	}

	public function testEditBootstrapShowsPictureUrl(): void {
		$name = $this->draftWithImage();
		$boot = $this->app->create_form->editBootstrap($this->initDataFor($this->owner), $this->editToken($this->owner));
		self::assertSame(self::BASE . $name, $boot['tournament']['image_url']);
		self::assertTrue($boot['images']['enabled']);
		self::assertSame(5242880, $boot['images']['max_bytes']);
		self::assertSame(1280, $boot['images']['max_width']);
	}

	public function testDeletingDraftDeletesFile(): void {
		$this->draftWithImage();
		$this->app->tournaments->deleteDraft($this->owner->id, 1);
		self::assertSame([], $this->mediaFiles());
	}

	public function testPurgeOfExpiredDraftsDeletesFile(): void {
		$this->draftWithImage();
		$this->clock->advance('+30 days');
		self::assertCount(1, $this->app->tournaments->purgeExpiredDrafts());
		self::assertSame([], $this->mediaFiles());
	}

	public function testSweepRemovesOnlyOldUnreferencedPictures(): void {
		$kept = $this->draftWithImage();
		$token = $this->tokenFor($this->owner);
		$fresh = $this->nameOf($this->uploadAs($this->owner, $token, $this->png())['url']);
		$orphan = str_repeat('c', 32) . '.jpg';
		$stranger_file = 'readme.txt';
		file_put_contents($this->media_dir . '/' . $orphan, 'x');
		file_put_contents($this->media_dir . '/' . $stranger_file, 'x');
		$old = time() - 2 * 86400;
		foreach ([$kept, $orphan, $stranger_file] as $name) {
			touch($this->media_dir . '/' . $name, $old);
		}
		self::assertSame(1, $this->app->images->sweep(time()));
		$files = $this->mediaFiles();
		sort($files);
		$expected = [$kept, $fresh, $stranger_file];
		sort($expected);
		self::assertSame($expected, $files);
	}

	public function testCronSweepsOrphans(): void {
		$orphan = str_repeat('d', 32) . '.jpg';
		mkdir($this->media_dir, 0775, true);
		file_put_contents($this->media_dir . '/' . $orphan, 'x');
		touch($this->media_dir . '/' . $orphan, $this->clock->now()->getTimestamp() - 2 * 86400);
		self::assertSame(1, $this->app->cron()->run()['images_swept']);
		self::assertSame([], $this->mediaFiles());
	}

	// ---- preview ----

	/**
		@return array<string, mixed>
	*/
	protected function previewOf(array $call): array {
		return $call['link_preview_options'];
	}

	public function testDraftCardShowsPictureAsLargePreviewAboveText(): void {
		$name = $this->draftWithImage();
		$messages = $this->http->sent('sendMessage');
		$card = end($messages);
		self::assertSame(
			['url' => self::BASE . $name, 'prefer_large_media' => true, 'show_above_text' => true],
			$this->previewOf($card),
		);
		self::assertStringNotContainsString('pop1.example.com', $card['text'], 'no link to the picture in the text');
		self::assertStringNotContainsString($name, $card['text']);
	}

	public function testDraftWithoutPictureKeepsPreviewDisabled(): void {
		$this->newDraft($this->owner, '23.11 20:07 | Кубок | 3 | 3,4,5 | 24');
		$messages = $this->http->sent('sendMessage');
		self::assertSame(['is_disabled' => true], $this->previewOf(end($messages)));
	}

	public function testAnnouncementHasPreviewAndEditsKeepIt(): void {
		$name = $this->draftWithImage();
		$this->http->reset();
		$this->publish();
		$posted = array_values(array_filter(
			$this->http->sent('sendMessage'),
			static fn (array $m): bool => (int) $m['chat_id'] === self::CHAT_ID,
		));
		self::assertCount(1, $posted);
		self::assertSame(self::BASE . $name, $this->previewOf($posted[0])['url']);
		self::assertTrue($this->previewOf($posted[0])['prefer_large_media']);
		self::assertTrue($this->previewOf($posted[0])['show_above_text']);
		self::assertStringNotContainsString('pop1.example.com', $posted[0]['text']);
		self::assertStringNotContainsString($name, $posted[0]['text']);

		$this->http->reset();
		$this->app->announcer->refresh(1);
		$edits = $this->http->sent('editMessageText');
		self::assertNotSame([], $edits);
		foreach ($edits as $edit) {
			if ((int) $edit['chat_id'] === self::CHAT_ID) {
				self::assertSame(self::BASE . $name, $this->previewOf($edit)['url'], 'edit keeps the picture');
			}
		}
	}

	public function testRemovingPictureOfPublishedTournamentDropsPreview(): void {
		$this->draftWithImage();
		$this->publish();
		$token = $this->editToken($this->owner);
		$this->http->reset();
		$this->app->create_form->editSubmit($this->initDataFor($this->owner), $token, $this->form(['image' => 'remove']));
		$group_edits = array_values(array_filter(
			$this->http->sent('editMessageText'),
			static fn (array $m): bool => (int) $m['chat_id'] === self::CHAT_ID,
		));
		self::assertNotSame([], $group_edits);
		self::assertSame(['is_disabled' => true], $this->previewOf(end($group_edits)));
	}

	public function testTournamentsCommandWithNumberHasPreviewButListDoesNot(): void {
		$name = $this->draftWithImage();
		$this->publish();
		$this->http->reset();
		$this->dispatch($this->privateMessage($this->owner, '/tournaments 1'));
		$messages = $this->http->sent('sendMessage');
		self::assertNotSame([], $messages);
		self::assertSame(self::BASE . $name, $this->previewOf($messages[0])['url']);
		foreach (array_slice($messages, 1) as $m) {
			self::assertSame(['is_disabled' => true], $this->previewOf($m), 'only the first message carries the picture');
		}

		$this->http->reset();
		$this->dispatch($this->privateMessage($this->owner, '/tournaments'));
		foreach ($this->http->sent('sendMessage') as $m) {
			self::assertSame(['is_disabled' => true], $this->previewOf($m), 'a list has no picture');
		}
	}

	public function testTournamentsNumberInGroupHasPreview(): void {
		$name = $this->draftWithImage();
		$this->publish();
		$this->http->reset();
		$this->dispatch($this->groupMessage($this->owner, '/tournaments 1'));
		$messages = $this->http->sent('sendMessage');
		self::assertNotSame([], $messages);
		self::assertSame(self::BASE . $name, $this->previewOf($messages[0])['url']);
	}

	// ---- the switch ----

	public function testSwitchOffHidesFieldAndRefusesUploads(): void {
		$this->images_on = false;
		$this->setUp();
		$token = $this->tokenFor($this->owner);
		$boot = $this->app->create_form->bootstrap($this->initDataFor($this->owner), $token);
		self::assertFalse($boot['images']['enabled']);
		$e = $this->failure(fn () => $this->uploadAs($this->owner, $token, $this->png()));
		self::assertSame('image', $e->field);
		self::assertSame([], $this->mediaFiles());
	}

	public function testSwitchOffIgnoresImageFieldAndShowsNoPreview(): void {
		$this->images_on = false;
		$this->setUp();
		$name = str_repeat('e', 32) . '.jpg';
		$this->newDraft($this->owner, '23.11 20:07 | Кубок | 3 | 3,4,5 | 24');
		$this->app->tournament_repo->update(1, ['image_path' => $name]);
		$this->http->reset();
		$this->app->drafts->show(1, $this->tgIdOf($this->owner), null, $this->owner->id);
		$messages = $this->http->sent('sendMessage');
		self::assertSame(['is_disabled' => true], $this->previewOf(end($messages)));
		$token = $this->editToken($this->owner);
		$boot = $this->app->create_form->editBootstrap($this->initDataFor($this->owner), $token);
		self::assertNull($boot['tournament']['image_url']);
		// «remove» is ignored while the switch is off: the picture stays
		$this->app->create_form->editSubmit($this->initDataFor($this->owner), $token, $this->form(['image' => 'remove']));
		self::assertSame($name, $this->app->tournament_repo->find(1)->image_path);
	}

	public function testSwitchOffStillDeletesFileOfDeletedDraft(): void {
		$this->images_on = false;
		$this->setUp();
		$name = str_repeat('f', 32) . '.jpg';
		mkdir($this->media_dir, 0775, true);
		file_put_contents($this->media_dir . '/' . $name, 'x');
		$this->newDraft($this->owner, '23.11 20:07 | Кубок | 3 | 3,4,5 | 24');
		$this->app->tournament_repo->update(1, ['image_path' => $name]);
		$this->app->tournaments->deleteDraft($this->owner->id, 1);
		self::assertSame([], $this->mediaFiles());
	}

	public function testPictureIsOffWithoutPublicUrl(): void {
		$images = new TournamentImages(new ImageStore($this->media_dir, 1024), $this->app->tournament_repo, true, null);
		self::assertFalse($images->enabled());
		self::assertNull($images->url(str_repeat('a', 32) . '.jpg'));
	}
}
