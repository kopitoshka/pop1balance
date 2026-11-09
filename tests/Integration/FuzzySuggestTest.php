<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Domain\Exception\PlayerSuggestError;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Tests\Support\IntegrationTestCase;

class FuzzySuggestTest extends IntegrationTestCase {

	public function testFuzzySuggestsSimilarUsername(): void {
		$this->app->players->ensureByTelegram(701, 'somenick', 'Some', $this->clock->now());
		try {
			$this->app->player_resolver->fromReference(
				'@somenic',
				$this->owner,
				['kind' => 'level_show', 'owner_only' => false],
				self::OWNER_TG_ID,
			);
			self::fail('expected PlayerSuggestError');
		} catch (PlayerSuggestError $e) {
			self::assertNotNull($e->pending_id);
			self::assertNotEmpty($e->suggestions);
			self::assertSame('somenick', $e->suggestions[0]->username);
			$markup = $e->replyMarkup();
			self::assertArrayHasKey('reply_markup', $markup);
			$btn = $markup['reply_markup']['inline_keyboard'][0][0];
			self::assertStringContainsString('Может, ты про', $btn['text']);
			self::assertStringStartsWith('sug:' . $e->pending_id . ':', $btn['callback_data']);
			self::assertLessThanOrEqual(64, strlen($btn['callback_data']));
		}
	}

	public function testNoSuggestionsKeepsStartMessage(): void {
		try {
			$this->app->player_resolver->fromReference('@zzzxqqq999', $this->owner);
			self::fail('expected ValidationError');
		} catch (PlayerSuggestError $e) {
			self::fail('should not suggest for garbage nick');
		} catch (ValidationError $e) {
			self::assertStringContainsString('ещё не знаком со мной', $e->getMessage());
		}
	}

	public function testLevelCommandShowsSuggestionButtons(): void {
		$this->app->players->ensureByTelegram(702, 'vasya_pro', 'Vasya', $this->clock->now());
		$this->dispatch($this->privateMessage($this->owner, '/level @vasya_pr'));
		$text = $this->http->lastText();
		self::assertStringContainsString('не нашла', $text);
		$sent = $this->http->sent('sendMessage');
		$last = end($sent);
		self::assertArrayHasKey('reply_markup', $last);
		$data = $last['reply_markup']['inline_keyboard'][0][0]['callback_data'];
		self::assertMatchesRegularExpression('/^sug:\d+:\d+$/', $data);
	}

	public function testSuggestionCallbackRunsLevelShow(): void {
		$target = $this->app->players->ensureByTelegram(703, 'petya', 'Petya', $this->clock->now());
		$this->rate($target, 5);
		try {
			$this->app->player_resolver->fromReference(
				'@pety',
				$this->owner,
				['kind' => 'level_show', 'owner_only' => false],
				self::OWNER_TG_ID,
			);
			self::fail('expected suggest');
		} catch (PlayerSuggestError $e) {
			$pending_id = (int) $e->pending_id;
		}
		$this->http->reset();
		$this->dispatch($this->press(
			$this->owner,
			'sug:' . $pending_id . ':' . $target->id,
		));
		self::assertStringContainsString('высокий', $this->http->lastText());
		self::assertStringContainsString('@petya', $this->http->lastText());
	}

	public function testOwnerOnlyHonouredOnCallback(): void {
		$target = $this->app->players->ensureByTelegram(704, 'kolya', 'Kolya', $this->clock->now());
		try {
			$this->app->player_resolver->fromReference(
				'@koly',
				$this->admin,
				['kind' => 'level_set', 'level' => 4, 'owner_only' => true, 'group_id' => self::CHAT_ID],
				$this->tgIdOf($this->admin),
			);
			self::fail('expected suggest');
		} catch (PlayerSuggestError $e) {
			$pending_id = (int) $e->pending_id;
		}
		// Persist pending under admin actor/chat — create was with admin id.
		$this->http->reset();
		$this->dispatch($this->press(
			$this->admin,
			'sug:' . $pending_id . ':' . $target->id,
			1,
			$this->tgIdOf($this->admin),
		));
		// answerCallbackQuery with owner-only alert
		$answers = $this->http->sent('answerCallbackQuery');
		self::assertNotEmpty($answers);
		self::assertStringContainsString('главному админу', (string) ($answers[0]['text'] ?? ''));
	}
}
