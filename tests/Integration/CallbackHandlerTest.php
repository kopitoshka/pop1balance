<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\App\PrivateGroups;
use P1Bot\Tests\Support\IntegrationTestCase;
use P1Bot\View\Messages;

class CallbackHandlerTest extends IntegrationTestCase {

	public function testOldGroupButtonsPointToPrivate(): void {
		$t = $this->openTournament(2, null, 2);
		$vasya = $this->player('vasya');
		foreach (['solo', 'unsolo', 'howto', 'pub', 'fix'] as $action) {
			$this->http->reset();
			$this->dispatch($this->press($vasya, $action . ':' . $t->id, 1, self::CHAT_ID));
			$answer = $this->http->sent('answerCallbackQuery')[0];
			self::assertSame(Messages::LEGACY_BUTTON, $answer['text'], $action);
			self::assertSame('https://t.me/pop1_bot?start=t_' . $t->id, $answer['url'], $action);
			self::assertSame([], $this->http->sent('sendMessage'), 'nothing is posted to the group');
		}
		self::assertNull($this->app->tournaments->lineup($t->id)->placementOf($vasya->id));
	}

	public function testSoloButtonsInPrivate(): void {
		$t = $this->openTournament(2, null, 2);
		$this->app->announcer->publish($t->id);
		$this->http->reset();

		$vasya = $this->player('vasya');
		$this->dispatch($this->press($vasya, 'psolo:' . $t->id));
		self::assertSame('main', $this->app->tournaments->lineup($t->id)->placementOf($vasya->id));
		self::assertStringContainsString('Записала тебя соло', $this->lastAnswer());
		$edit = $this->http->sent('editMessageText')[0];
		self::assertSame(self::CHAT_ID, $edit['chat_id'], 'the group card is edited');
		self::assertStringContainsString("🙋 <b>Ищут команду:</b>\n@vasya", $edit['text']);
		$sent = $this->http->sent('sendMessage');
		self::assertSame($this->tgIdOf($vasya), end($sent)['chat_id'], 'no message in the group');
		self::assertSame('punsolo:' . $t->id, end($sent)['reply_markup']['inline_keyboard'][0][0]['callback_data']);

		$this->dispatch($this->press($vasya, 'psolo:' . $t->id));
		self::assertStringContainsString('уже записан', $this->lastAnswer());

		$this->dispatch($this->press($this->player('a'), 'psolo:' . $t->id));
		$this->dispatch($this->press($this->player('b'), 'psolo:' . $t->id));
		self::assertStringContainsString('ты в резерве', $this->lastAnswer());

		$this->dispatch($this->press($vasya, 'punsolo:' . $t->id));
		self::assertStringContainsString('Сняла тебя', $this->lastAnswer());
		self::assertNull($this->app->tournaments->lineup($t->id)->placementOf($vasya->id));
		$sent = $this->http->sent('sendMessage');
		self::assertSame('psolo:' . $t->id, end($sent)['reply_markup']['inline_keyboard'][0][0]['callback_data']);
	}

	public function testTeamButtonsInPrivate(): void {
		$t = $this->openTournament(2, null);
		$cap = $this->player('cap');
		$mate = $this->player('mate');

		$this->dispatch($this->press($cap, 'pteam:' . $t->id));
		self::assertStringContainsString('Как назовём команду', $this->http->lastText());
		$conversation = $this->app->conversations->findLatest($this->tgIdOf($cap), $cap->id, $this->clock->now());
		self::assertNotNull($conversation, 'the AI continues the dialog');
		self::assertStringContainsString('register_team', $conversation['history'][0]['content']);

		$entry = $this->app->teams->createTeam($t->id, $cap->id, 'Wolves', [$mate->id]);
		$this->dispatch($this->press($cap, 'pteam:' . $t->id));
		self::assertStringContainsString('уже в команде', $this->lastAnswer());

		$this->dispatch($this->press($mate, 'pleave:' . $entry->team->id));
		self::assertStringContainsString('вышел из команды', $this->lastAnswer());
		self::assertNull($this->app->teams->teamOf($t->id, $mate->id));

		$this->dispatch($this->press($cap, 'pdissolve:' . $entry->team->id));
		self::assertStringContainsString('распущена', $this->lastAnswer());
		self::assertNull($this->app->teams->teamOf($t->id, $cap->id));
	}

	public function testNonMembersCannotJoin(): void {
		$t = $this->openTournament(2, null);
		$this->http->onTelegram('getChatMember', static function (): array {
			return ['ok' => true, 'result' => ['status' => 'left', 'user' => ['id' => 1]]];
		});
		$stranger = $this->player('stranger');
		$this->dispatch($this->press($stranger, 'psolo:' . $t->id));
		self::assertSame(PrivateGroups::NOT_MEMBER, $this->lastAnswer());
		self::assertNull($this->app->tournaments->lineup($t->id)->placementOf($stranger->id));
		$this->dispatch($this->press($stranger, 'pteam:' . $t->id));
		self::assertSame(PrivateGroups::NOT_MEMBER, $this->lastAnswer());
	}

	public function testMembershipCheckErrorAllowsJoining(): void {
		$t = $this->openTournament(2, null);
		$this->http->onTelegram('getChatMember', static function (): array {
			return ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: member list is inaccessible'];
		});
		$vasya = $this->player('vasya');
		$this->dispatch($this->press($vasya, 'psolo:' . $t->id));
		self::assertSame('main', $this->app->tournaments->lineup($t->id)->placementOf($vasya->id));
	}

	public function testOpenButtonShowsTournamentWithStateButtons(): void {
		$t = $this->openTournament(2, null);
		$vasya = $this->player('vasya');
		$this->dispatch($this->press($vasya, 'popen:' . $t->id));
		$sent = $this->http->sent('sendMessage');
		$last = end($sent);
		self::assertSame($this->tgIdOf($vasya), $last['chat_id']);
		self::assertStringContainsString('Субботний кубок', $last['text']);
		$buttons = array_column(array_merge(...$last['reply_markup']['inline_keyboard']), 'callback_data');
		self::assertSame(['psolo:' . $t->id, 'pteam:' . $t->id], $buttons);
	}

	public function testGroupPicker(): void {
		$this->app->chats->ensure(-100777, 'Вторая группа');
		$vasya = $this->player('vasya');
		$this->dispatch($this->press($vasya, 'grp:-100777'));
		self::assertSame(-100777, $this->app->players->dmGroup($vasya->id));
		self::assertStringContainsString('Вторая группа', $this->http->lastText());

		$this->dispatch($this->press($vasya, 'grp:-100555'));
		self::assertStringContainsString('больше нет', $this->lastAnswer());
		self::assertSame(-100777, $this->app->players->dmGroup($vasya->id));
	}

	public function testPublishAndFixButtonsOnPrivateDraftCard(): void {
		$this->dispatch($this->privateMessage($this->owner, '/new 23.11 20:00 | Кубок | 2 | 3,4'));
		$card = $this->http->sent('sendMessage');
		self::assertSame(self::OWNER_TG_ID, end($card)['chat_id'], 'the card is shown in private');
		$card_id = $this->app->tournament_repo->find(1)->card_msg_id;

		// Someone else cannot publish or fix.
		$vasya = $this->player('vasya');
		$this->dispatch($this->press($vasya, 'pub:1', $card_id));
		self::assertStringContainsString('автор или главный админ', $this->lastAnswer());
		$this->dispatch($this->press($vasya, 'fix:1', $card_id));
		self::assertStringContainsString('автор или главный админ', $this->lastAnswer());
		self::assertSame('draft', $this->app->tournament_repo->find(1)->status);

		// «Поправить» explains how to correct, publishes nothing.
		$this->dispatch($this->press($this->owner, 'fix:1', $card_id));
		self::assertStringContainsString('Напиши, что поправить', $this->lastAnswer());
		self::assertSame('draft', $this->app->tournament_repo->find(1)->status);

		// A button on an outdated card does nothing.
		$this->dispatch($this->press($this->owner, 'pub:1', $card_id - 1));
		self::assertStringContainsString('устарела', $this->lastAnswer());
		self::assertSame('draft', $this->app->tournament_repo->find(1)->status);

		$this->http->reset();
		$this->dispatch($this->press($this->owner, 'pub:1', $card_id));
		self::assertStringContainsString('Опубликовано', $this->lastAnswer());
		$t = $this->app->tournament_repo->find(1);
		self::assertSame('registration', $t->status);
		self::assertNotNull($t->announce_msg_id);
		$sent = $this->http->sent('sendMessage');
		self::assertSame(self::CHAT_ID, $sent[0]['chat_id'], 'the announcement goes to the group');
		self::assertSame('https://t.me/pop1_bot?start=t_1', $sent[0]['reply_markup']['inline_keyboard'][0][0]['url']);
		self::assertSame(self::OWNER_TG_ID, end($sent)['chat_id']);
		self::assertStringContainsString('во всех группах', end($sent)['text']);
		$edits = $this->http->sent('editMessageText');
		self::assertSame(self::OWNER_TG_ID, end($edits)['chat_id'], 'the private card is edited');
		self::assertSame([], end($edits)['reply_markup']['inline_keyboard'], 'card buttons removed');

		$this->dispatch($this->press($this->owner, 'pub:1', $card_id));
		self::assertStringContainsString('уже не черновик', $this->lastAnswer());
	}

	protected function lastAnswer(): string {
		$answers = $this->http->sent('answerCallbackQuery');
		return (string) (end($answers)['text'] ?? '');
	}
}
