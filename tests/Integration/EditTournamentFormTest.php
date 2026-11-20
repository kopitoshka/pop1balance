<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\App\CreateTournamentForm;
use P1Bot\App\WebAppFormError;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Support\DateFormat;
use P1Bot\Tests\Support\IntegrationTestCase;

/**
	The draft card opens the same Mini App, prefilled, and saves only a manageable draft.
*/
class EditTournamentFormTest extends IntegrationTestCase {

	protected function config(): array {
		$c = parent::config();
		$c['telegram']['webhook_url'] = 'https://pop1.example.com/webhook.php';
		return $c;
	}

	protected function token(?Player $actor = null, int $id = 1): string {
		$actor = $actor ?? $this->owner;
		$this->app->chats->ensure((int) $actor->tg_user_id, $actor->display_name);
		$button = $this->app->create_form->editButton($this->app->tournaments->get($id), (int) $actor->tg_user_id, $actor->id);
		self::assertNotNull($button);
		parse_str((string) parse_url($button['web_app']['url'], PHP_URL_QUERY), $q);
		self::assertSame('edit', $q['mode']);
		return $q['ctx'];
	}

	protected function form(array $override = []): array {
		return $override + [
			'title' => 'Обновлённый кубок <тест>',
			'date' => '2026-12-31',
			'time' => '23:07',
			'team_size' => 5,
			'level_mode' => 'players',
			'levels' => [2, 3, 4, 5, 6],
			'max_players' => 45,
		];
	}

	protected function errorFrom(callable $call, string $reason): WebAppFormError {
		try {
			$call();
			self::fail('Expected a form error');
		} catch (WebAppFormError $e) {
			self::assertSame($reason, $e->reason);
			return $e;
		}
	}

	public function testPrivateDraftCardHasWebAppEditButton(): void {
		$this->newDraft($this->owner, '23.11 20:07 | Кубок | 3 | 3,4,5 | 24');
		$messages = $this->http->sent('sendMessage');
		$card = end($messages);
		$button = $card['reply_markup']['inline_keyboard'][0][1];
		self::assertSame('✏️ Поправить', $button['text']);
		self::assertArrayNotHasKey('callback_data', $button);
		self::assertStringContainsString('/webapp/create.html?ctx=', $button['web_app']['url']);
		self::assertStringEndsWith('&mode=edit', $button['web_app']['url']);
		self::assertSame('pub:1', $card['reply_markup']['inline_keyboard'][0][0]['callback_data']);
	}

	public function testGroupDraftCardUsesPrivateDeepLink(): void {
		$this->newDraft($this->owner, '23.11 20:00 | Кубок | 3 | 10 | 24');
		$this->app->drafts->show(1, self::CHAT_ID, null, $this->owner->id);
		$messages = $this->http->sent('sendMessage');
		$button = end($messages)['reply_markup']['inline_keyboard'][0][1];
		self::assertArrayNotHasKey('web_app', $button);
		self::assertSame('https://t.me/pop1_bot?start=t_1', $button['url']);
		// The deep link opens a fresh card with a web_app button in private.
		$this->dispatch($this->privateMessage($this->owner, '/start t_1'));
		$messages = $this->http->sent('sendMessage');
		self::assertArrayHasKey('web_app', end($messages)['reply_markup']['inline_keyboard'][0][1]);
	}

	public function testBootstrapPrefillsPlayersAndMskRegardlessOfServerTimezone(): void {
		$this->newDraft($this->owner, '23.11 20:07 | Кубок <тест> | 3 | 3,4,5 | 30');
		$zone = date_default_timezone_get();
		date_default_timezone_set('America/Los_Angeles');
		try {
			$boot = $this->app->create_form->editBootstrap($this->initDataFor($this->owner), $this->token());
		} finally {
			date_default_timezone_set($zone);
		}
		self::assertSame('edit_tournament', $boot['action']);
		self::assertSame('edit', $boot['mode']);
		self::assertSame([
			'id' => 1, 'number' => 1, 'title' => 'Кубок <тест>', 'date' => '2026-11-23', 'time' => '20:07',
			'team_size' => 3, 'level_mode' => 'players', 'levels' => [3, 4, 5], 'points' => 12, 'max_players' => 30,
		], $boot['tournament']);
	}

	public function testBootstrapPrefillsSumAndLoadsCurrentRatherThanTokenSnapshot(): void {
		$this->newDraft($this->owner, '23.11 20:00 | Кубок | 3 | 10 | 24');
		$token = $this->token();
		$this->dispatch($this->privateMessage($this->owner, '/edit 1 название Новое название'));
		$boot = $this->app->create_form->editBootstrap($this->initDataFor($this->owner), $token);
		self::assertSame('Новое название', $boot['tournament']['title']);
		self::assertSame('sum', $boot['tournament']['level_mode']);
		self::assertSame([], $boot['tournament']['levels']);
		self::assertSame(10, $boot['tournament']['points']);
	}

	public function testEditSubmitSavesAllFieldsAndShowsUpdatedDraftCard(): void {
		$this->newDraft($this->owner, '23.11 20:00 | Кубок | 3 | 10 | 24');
		$old = $this->app->tournaments->get(1)->card_msg_id;
		$result = $this->app->create_form->editSubmit($this->initDataFor($this->owner), $this->token(), $this->form());
		self::assertSame(['ok' => true, 'close' => true, 'tournament_id' => 1], $result);
		$t = $this->app->tournaments->get(1);
		self::assertSame('Обновлённый кубок <тест>', $t->title);
		self::assertSame('2026-12-31 20:07:00', DateFormat::toDb($t->starts_at));
		self::assertSame(5, $t->team_size);
		self::assertSame('2,3,4,5,6', $t->reference_team);
		self::assertSame(20, $t->max_points);
		self::assertSame(45, $t->max_players);
		self::assertSame(TournamentStatus::DRAFT, $t->status);
		self::assertNotSame($old, $t->card_msg_id);
		self::assertSame($this->owner->id, $t->card_shown_to);
		self::assertStringContainsString('&lt;тест&gt;', $this->http->lastText());
		self::assertStringContainsString('Публикуем', $this->http->lastText());
	}

	public function testEditSumModeAndSizesOneAndTwelve(): void {
		$this->newDraft($this->owner, '23.11 20:00 | Кубок | 3 | 3,3,4 | 24');
		foreach ([1, 12] as $size) {
			$this->app->create_form->editSubmit($this->initDataFor($this->owner), $this->token(), $this->form([
				'team_size' => $size, 'level_mode' => 'sum', 'points' => $size * 4, 'max_players' => $size * 2,
			]));
			$t = $this->app->tournaments->get(1);
			self::assertSame($size, $t->team_size);
			self::assertSame($size * 4, $t->max_points);
			if ($size > 1) {
				self::assertNull($t->reference_team);
			}
		}
	}

	public function testAuthorCanEditWithoutCreatePermissionAndOwnerCanEditOthers(): void {
		$author = $this->player('author');
		// CreateAccess is independent of management rights. Make an existing draft the author's.
		$this->newDraft($this->owner, '23.11 20:00 | Кубок | 3 | 10 | 24');
		$this->db->run('UPDATE tournaments SET organizer_id = :id WHERE id = 1', ['id' => $author->id]);
		$this->withoutSharedOwnerGroup($author);
		self::assertFalse($this->app->create_form->canCreate($author));
		$this->app->create_form->editBootstrap($this->initDataFor($author), $this->token($author));
		$this->app->create_form->editSubmit($this->initDataFor($author), $this->token($author), $this->form());
		$this->app->create_form->editSubmit($this->initDataFor($this->owner), $this->token(), $this->form(['title' => 'Правка владельца']));
		self::assertSame('Правка владельца', $this->app->tournaments->get(1)->title);
	}

	public function testForeignActorCannotBootstrapOrSubmitEvenWithOwnContext(): void {
		$this->newDraft($this->owner, '23.11 20:00 | Кубок | 3 | 10 | 24');
		$actor = $this->player('outsider');
		$token = $this->token($actor);
		$this->errorFrom(fn() => $this->app->create_form->editBootstrap($this->initDataFor($actor), $token), WebAppFormError::FORBIDDEN);
		$this->errorFrom(fn() => $this->app->create_form->editSubmit($this->initDataFor($actor), $token, $this->form()), WebAppFormError::FORBIDDEN);
		self::assertSame('Кубок', $this->app->tournaments->get(1)->title);
	}

	public function testNoLongerDraftCannotBootstrapOrSubmit(): void {
		$this->newDraft($this->owner, '23.11 20:00 | Кубок | 3 | 10 | 24');
		$token = $this->token();
		$this->app->tournaments->openRegistration($this->owner->id, 1);
		$e = $this->errorFrom(fn() => $this->app->create_form->editBootstrap($this->initDataFor($this->owner), $token), WebAppFormError::NOT_DRAFT);
		self::assertStringContainsString('уже не черновик', $e->getMessage());
		$this->errorFrom(fn() => $this->app->create_form->editSubmit($this->initDataFor($this->owner), $token, $this->form()), WebAppFormError::NOT_DRAFT);
		self::assertSame('Кубок', $this->app->tournaments->get(1)->title);
	}

	public function testDeletedDraftAndForeignUserAndWrongModeFailSafely(): void {
		$this->newDraft($this->owner, '23.11 20:00 | Кубок | 3 | 10 | 24');
		$token = $this->token();
		$other = $this->player('other');
		$this->errorFrom(fn() => $this->app->create_form->editBootstrap($this->initDataFor($other), $token), WebAppFormError::FORBIDDEN);
		$this->errorFrom(fn() => $this->app->create_form->bootstrap($this->initDataFor($this->owner), $token), WebAppFormError::EXPIRED);
		$create = $this->app->create_form->issueToken($this->owner);
		$this->errorFrom(fn() => $this->app->create_form->editSubmit($this->initDataFor($this->owner), $create, $this->form()), WebAppFormError::EXPIRED);
		$this->app->tournaments->deleteDraft($this->owner->id, 1);
		$this->errorFrom(fn() => $this->app->create_form->editBootstrap($this->initDataFor($this->owner), $token), WebAppFormError::NOT_DRAFT);
	}

	public function testValidationAndExpiredContext(): void {
		$this->newDraft($this->owner, '23.11 20:00 | Кубок | 3 | 10 | 24');
		$token = $this->token();
		$e = $this->errorFrom(fn() => $this->app->create_form->editSubmit($this->initDataFor($this->owner), $token, $this->form(['title' => ''])), WebAppFormError::INVALID);
		self::assertSame('title', $e->field);
		self::assertSame('Кубок', $this->app->tournaments->get(1)->title);
		$this->clock->advance('+7 hours');
		$e = $this->errorFrom(fn() => $this->app->create_form->editBootstrap($this->initDataFor($this->owner), $token), WebAppFormError::EXPIRED);
		self::assertStringContainsString('/open', $e->getMessage());
	}

	public function testOldFixCallbackOffersFormRatherThanAskingForText(): void {
		$this->newDraft($this->owner, '23.11 20:00 | Кубок | 3 | 10 | 24');
		$this->dispatch($this->press($this->owner, 'fix:1'));
		self::assertSame(CreateTournamentForm::EDIT_OFFER_TEXT, $this->http->lastText());
		$messages = $this->http->sent('sendMessage');
		$button = end($messages)['reply_markup']['inline_keyboard'][0][0];
		self::assertStringEndsWith('&mode=edit', $button['web_app']['url']);
	}
}
