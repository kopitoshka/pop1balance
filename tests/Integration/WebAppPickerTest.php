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

	public function testTeamAddOfferIncludesWebAppButton(): void {
		$cap = $this->player('capw');
		$t = $this->openTournament(3);
		$entry = $this->app->teams->createTeam($t->id, $cap->id, 'Webbers', []);
		$this->dispatch($this->press($cap, 'padd:' . $entry->team->id));
		$found = null;
		foreach ($this->http->sent('sendMessage') as $msg) {
			foreach ($msg['reply_markup']['inline_keyboard'] ?? [] as $row) {
				foreach ($row as $btn) {
					if (($btn['text'] ?? '') === WebAppPicker::BUTTON_TEXT) {
						$found = $btn;
					}
				}
			}
		}
		self::assertIsArray($found);
		self::assertStringStartsWith('https://pop1.example.com/webapp/picker.html?ctx=', $found['web_app']['url']);
	}

	public function testSearchAndSubmitAddsToTeam(): void {
		$cap = $this->player('capadd');
		$mate = $this->player('webmate');
		$this->app->nicks->setOwn($mate->id, 'MataNick');
		$t = $this->openTournament(3);
		$entry = $this->app->teams->createTeam($t->id, $cap->id, 'Foxes', []);
		$btn = $this->app->webapp_picker->teamAddButton($cap, $this->tgIdOf($cap), $entry->team->id);
		self::assertIsArray($btn);
		$token = $this->tokenFromButton($btn);
		$init = $this->initDataFor($cap);

		$boot = $this->app->webapp_picker->bootstrap($init, $token);
		self::assertSame(WebAppPicker::ACTION_TEAM_ADD, $boot['action']);
		self::assertSame(2, $boot['max_select']);

		$found = $this->app->webapp_picker->search($init, $token, 'MataNick');
		$ids = array_column($found['players'], 'id');
		self::assertContains($mate->id, $ids);
		self::assertNotContains($cap->id, $ids, 'captain already on the team');

		$this->http->reset();
		$result = $this->app->webapp_picker->submit($init, $token, [$mate->id], null);
		self::assertTrue($result['close']);
		$members = $this->app->teams->get($entry->team->id)->members;
		$member_ids = array_map(static fn ($m) => $m->player_id, $members);
		self::assertContains($mate->id, $member_ids);
		self::assertStringContainsString('MataNick', $this->http->lastText());
	}

	public function testWrongUserRejected(): void {
		$cap = $this->player('capown');
		$other = $this->player('intruder');
		$t = $this->openTournament(3);
		$entry = $this->app->teams->createTeam($t->id, $cap->id, 'Gate', []);
		$btn = $this->app->webapp_picker->teamAddButton($cap, $this->tgIdOf($cap), $entry->team->id);
		$token = $this->tokenFromButton($btn);
		$this->expectException(\P1Bot\Domain\Exception\ValidationError::class);
		$this->app->webapp_picker->search($this->initDataFor($other), $token, 'x');
	}

	public function testExpiredContextRejected(): void {
		$cap = $this->player('capold');
		$t = $this->openTournament(3);
		$entry = $this->app->teams->createTeam($t->id, $cap->id, 'Oldies', []);
		$btn = $this->app->webapp_picker->teamAddButton($cap, $this->tgIdOf($cap), $entry->team->id);
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
		$this->expectException(\P1Bot\Domain\Exception\DomainError::class);
		$this->app->webapp_picker->submit($this->initDataFor($actor), $token, [$target->id], 5);
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

	public function testSubmitClearsTeamAddPending(): void {
		$cap = $this->player('cappnd');
		$mate = $this->player('pendmate');
		$t = $this->openTournament(3);
		$entry = $this->app->teams->createTeam($t->id, $cap->id, 'Pend', []);
		$this->dispatch($this->press($cap, 'padd:' . $entry->team->id));
		self::assertTrue($this->app->team_add->hasPending($cap->id, $this->tgIdOf($cap)));
		$btn = $this->app->webapp_picker->teamAddButton($cap, $this->tgIdOf($cap), $entry->team->id);
		$token = $this->tokenFromButton($btn);
		$this->app->webapp_picker->submit($this->initDataFor($cap), $token, [$mate->id], null);
		self::assertFalse($this->app->team_add->hasPending($cap->id, $this->tgIdOf($cap)));
	}
}
