<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\App\WebAppPicker;
use P1Bot\Domain\Levels;
use P1Bot\Telegram\WebAppInitData;
use P1Bot\Tests\Support\IntegrationTestCase;

class WebAppPickerTest extends IntegrationTestCase {

	protected function config(): array {
		$c = parent::config();
		$c['telegram']['webhook_url'] = 'https://pop1.example.com/webhook.php';
		return $c;
	}

	protected function initDataFor(\P1Bot\Domain\Model\Player $player): string {
		return WebAppInitData::sign([
			'auth_date' => (string) $this->clock->now()->getTimestamp(),
			'user' => (string) json_encode([
				'id' => $player->tg_user_id,
				'first_name' => $player->display_name,
				'username' => $player->username,
			], JSON_UNESCAPED_UNICODE),
		], (string) $this->config()['telegram']['token']);
	}

	protected function tokenFromButton(array $btn): string {
		$url = $btn['web_app']['url'] ?? '';
		self::assertStringContainsString('/webapp/picker.html?ctx=', $url);
		parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
		self::assertNotSame('', $q['ctx'] ?? '');
		return (string) $q['ctx'];
	}

	public function testWrongUserRejected(): void {
		$cap = $this->player('capown');
		$other = $this->player('intruder');
		$btn = $this->app->webapp_picker->levelButton($cap, $this->tgIdOf($cap), false);
		$token = $this->tokenFromButton($btn);
		$this->expectException(\P1Bot\Domain\Exception\ValidationError::class);
		$this->app->webapp_picker->search($this->initDataFor($other), $token, 'x');
	}

	public function testExpiredContextRejected(): void {
		$cap = $this->player('capold');
		$btn = $this->app->webapp_picker->levelButton($cap, $this->tgIdOf($cap), false);
		$token = $this->tokenFromButton($btn);
		$this->clock->advance('+11 minutes');
		$this->expectException(\P1Bot\Domain\Exception\ValidationError::class);
		$this->app->webapp_picker->bootstrap($this->initDataFor($cap), $token);
	}

	public function testLevelPickerShowsAndOwnerCanSet(): void {
		$target = $this->player('lvluser');
		$this->app->nicks->setOwn($target->id, 'Zoloto');
		$btn = $this->app->webapp_picker->levelButton($this->owner, self::OWNER_TG_ID, true);
		self::assertIsArray($btn);
		$token = $this->tokenFromButton($btn);
		$init = $this->initDataFor($this->owner);
		$found = $this->app->webapp_picker->search($init, $token, 'Zoloto');
		self::assertContains($target->id, array_column($found['players'], 'id'));

		$this->http->reset();
		$this->app->webapp_picker->submit($init, $token, [$target->id], 4);
		self::assertStringContainsString('средний+', $this->http->lastText());
		self::assertSame(4, $this->app->balances->explicitLevel($this->app->balances->viewScaleId(), $target->id));
	}

	public function testNonOwnerCannotSetLevelViaPicker(): void {
		$actor = $this->player('regular');
		$target = $this->player('someone');
		$btn = $this->app->webapp_picker->levelButton($actor, $this->tgIdOf($actor), false);
		$token = $this->tokenFromButton($btn);
		try {
			$this->app->webapp_picker->submit($this->initDataFor($actor), $token, [$target->id], 5);
			self::fail('regular player set someone else\'s level via picker');
		} catch (\P1Bot\Domain\Exception\DomainError $e) {
			self::assertSame(\P1Bot\Domain\AccessPolicy::LEVELS_OWNER_ONLY, $e->getMessage());
		}

		$btn = $this->app->webapp_picker->levelButton($actor, $this->tgIdOf($actor), false);
		$token = $this->tokenFromButton($btn);
		try {
			$this->app->webapp_picker->submit($this->initDataFor($actor), $token, [$actor->id], 5);
			self::fail('regular player set their own level via picker');
		} catch (\P1Bot\Domain\Exception\DomainError $e) {
			self::assertSame(\P1Bot\Domain\AccessPolicy::LEVEL_SELF, $e->getMessage());
		}
	}

	public function testLevelCommandShowsPickerButton(): void {
		$this->dispatch($this->privateMessage($this->owner, '/level'));
		$found = false;
		foreach ($this->http->sent('sendMessage') as $msg) {
			foreach ($msg['reply_markup']['inline_keyboard'] ?? [] as $row) {
				foreach ($row as $btn) {
					if (($btn['text'] ?? '') === WebAppPicker::BUTTON_TEXT) {
						$found = true;
					}
				}
			}
		}
		self::assertTrue($found, '/level without args should offer the picker');
	}

	/**
		@dataProvider oldActions
	*/
	public function testOldTeamPickerContextsAreRejected(string $action): void {
		$cap = $this->player('oldpicker');
		$token = bin2hex(random_bytes(16));
		$this->app->webapp_contexts->create(
			$token,
			$cap->id,
			$this->tgIdOf($cap),
			$action,
			[],
			$this->clock->now()->modify('+5 minutes'),
			$this->clock->now(),
		);
		$this->expectException(\P1Bot\Domain\Exception\ValidationError::class);
		$this->app->webapp_picker->bootstrap($this->initDataFor($cap), $token);
	}

	public static function oldActions(): array {
		return [['team_add'], ['registration_fill'], ['org_captain'], ['team_form'], ['create_tournament']];
	}
}
