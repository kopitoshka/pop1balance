<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Telegram\Update;
use P1Bot\Tests\Support\IntegrationTestCase;
use P1Bot\View\Messages;

class EditedMessageTest extends IntegrationTestCase {

	protected function config(): array {
		$config = parent::config();
		$config['openai']['api_key'] = 'sk-test'; // AI on: it still must not be called
		return $config;
	}

	/**
		Wraps a group message as an edited_message update.
	*/
	protected function edited(array $update): array {
		$update['edited_message'] = $update['message'] + ['edit_date' => 1];
		unset($update['message']);
		return $update;
	}

	public function testEditedMentionGetsOneReplyAndIsNotProcessed(): void {
		$vasya = $this->player('vasya');
		$update = $this->edited($this->groupMessage(
			$vasya,
			'@' . self::BOT_USERNAME . ' /new 23.11 20:00 | Кубок | 3 | 10',
		));
		$this->dispatch($update);

		$sent = $this->http->sent('sendMessage');
		self::assertCount(1, $sent);
		self::assertSame(
			'Исправленные сообщения я не вижу 🙈 Напиши, пожалуйста, заново новым сообщением',
			$sent[0]['text'],
		);
		self::assertSame(Messages::EDITED_MESSAGE, $sent[0]['text']);
		self::assertSame($update['edited_message']['message_id'], $sent[0]['reply_parameters']['message_id']);
		self::assertSame(
			[],
			array_filter($this->http->requests, static fn (array $r): bool => str_ends_with($r['url'], '/responses')),
			'no AI call',
		);
		self::assertNull($this->app->tournament_repo->find(1));

		// Edited again: silence.
		$update['update_id'] += 1000;
		$update['edited_message']['text'] .= '!';
		$this->dispatch($update);
		self::assertCount(1, $this->http->sent('sendMessage'));
	}

	public function testEditedCommandOfOwnerIsNotExecuted(): void {
		$this->dispatch($this->edited($this->groupMessage(
			$this->owner,
			'/new@' . self::BOT_USERNAME . ' 23.11 20:00 | Кубок | 3 | 10',
		)));
		self::assertNull($this->app->tournament_repo->find(1));
		self::assertSame(Messages::EDITED_MESSAGE, $this->http->lastText());
	}

	public function testEditedReplyToBotGetsReply(): void {
		$this->dispatch($this->edited($this->groupMessage($this->player('vasya'), 'запиши меня', ['reply_to_message' => [
			'message_id' => 42,
			'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Bot'],
		]])));
		self::assertSame(Messages::EDITED_MESSAGE, $this->http->lastText());
	}

	public function testEditsNotAddressedToBotAreIgnored(): void {
		$vasya = $this->player('vasya');
		$this->dispatch($this->edited($this->groupMessage($vasya, 'просто болтаем, @petya')));
		$this->dispatch($this->edited($this->groupMessage($vasya, '/tournaments')));
		$this->dispatch($this->edited($this->groupMessage($vasya, 'ответ человеку', ['reply_to_message' => [
			'message_id' => 7,
			'from' => ['id' => 999, 'is_bot' => false, 'first_name' => 'Petya'],
		]])));
		self::assertSame([], $this->http->sent('sendMessage'));
	}

	public function testEditedMessageIsInAllowedUpdates(): void {
		self::assertContains('edited_message', Update::ALLOWED_UPDATES);
	}
}
