<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Domain\Lineup;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\TournamentService;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Repository\ChatRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Repository\TournamentAnnouncementRepository;
use P1Bot\Support\Logger;
use P1Bot\Telegram\BotIdentity;
use P1Bot\Telegram\TelegramApi;
use P1Bot\Telegram\TelegramError;
use P1Bot\View\AnnouncementView;
use P1Bot\View\DeepLink;
use Throwable;

/**
	Posts the tournament announcement into every active group and keeps those messages up to date.
*/
class Announcer {
	public const BUTTON = '💬 Записаться / вопросы в личке';

	public function __construct(
		protected TelegramApi $api,
		protected TournamentService $tournaments,
		protected PlayerRepository $players,
		protected BotIdentity $bot,
		protected Logger $logger,
		protected ?TournamentResolver $resolver = null,
		protected ?ChatRepository $chats = null,
		protected ?TournamentAnnouncementRepository $announcements = null,
	) {
	}

	/**
		Sets the short number users see (№N = tournament id).
	*/
	public function number(Tournament $t): Tournament {
		return $this->resolver !== null ? $this->resolver->number($t) : $t;
	}

	/**
		After a tournament was created, published, finished or cancelled, re-render other active
		announcements (status lines). Numbers are global ids and do not shift. Never throws.
	*/
	public function renumber(?int $except_id = null): void {
		try {
			foreach ($this->tournaments->listAllActive() as $t) {
				if ($t->id !== $except_id) {
					$this->refresh($t->id);
				}
			}
		} catch (Throwable $e) {
			$this->logger->exception($e, 'renumber');
		}
	}

	/**
		Sends a new announcement into every active group and remembers message ids.
		Returns the message id in the first group that accepted it (0 if none).
	*/
	public function publish(int $tournament_id): int {
		$t = $this->tournaments->get($tournament_id);
		$text = $this->text($t);
		$markup = $this->markup($t);
		$messages = [];
		$primary = 0;
		$groups = $this->chats !== null ? $this->chats->listActiveGroups() : [];
		if ($groups === [] && $t->chat_id !== null) {
			$groups = [$t->chat_id => ''];
		}
		foreach (array_keys($groups) as $chat_id) {
			try {
				$message_id = $this->api->sendMessage((int) $chat_id, $text, null, $markup);
				$messages[(int) $chat_id] = $message_id;
				if ($primary === 0) {
					$primary = $message_id;
				}
			} catch (Throwable $e) {
				$this->logger->exception($e, sprintf('publish %d to %d', $tournament_id, $chat_id));
			}
		}
		if ($this->announcements !== null) {
			$this->announcements->replaceAll($tournament_id, $messages);
		}
		if ($primary !== 0) {
			$this->tournaments->setAnnounceMessage($t->id, $primary);
		}
		return $primary;
	}

	/**
		Re-renders every stored announcement after any change. Never throws.
	*/
	public function refresh(int $tournament_id): void {
		try {
			$t = $this->tournaments->get($tournament_id);
			$targets = $this->announcementTargets($t);
			if ($targets === []) {
				return;
			}
			$text = $this->text($t);
			$markup = $this->markup($t);
			foreach ($targets as $chat_id => $message_id) {
				try {
					$this->api->editMessageText($chat_id, $message_id, $text, $markup);
				} catch (TelegramError $e) {
					if (!$e->isNotModified()) {
						$this->logger->exception($e, sprintf('refresh announcement %d in %d', $tournament_id, $chat_id));
					}
				} catch (Throwable $e) {
					$this->logger->exception($e, sprintf('refresh announcement %d in %d', $tournament_id, $chat_id));
				}
			}
		} catch (Throwable $e) {
			$this->logger->exception($e, 'refresh announcement ' . $tournament_id);
		}
	}

	public function text(Tournament $t, ?Lineup $lineup = null): string {
		$lineup = $lineup ?? $this->tournaments->lineup($t->id);
		return $this->view($t, $lineup)->render($t, $lineup, $this->tournaments->isUserRegistrationOpen($t));
	}

	/**
		Lineup lists only (for the AI tournament_info result).
	*/
	public function lineupText(Tournament $t): string {
		$lineup = $this->tournaments->lineup($t->id);
		return $this->view($t, $lineup)->lineupText($lineup, $t->team_size);
	}

	/**
		Pretty block for /tournaments and the AI list, cut with a hint to /info N when too long.
	*/
	public function listBlock(
		Tournament $t,
		int $limit = AnnouncementView::MESSAGE_LIMIT,
		string $footer = '',
	): string {
		$lineup = $this->tournaments->lineup($t->id);
		return $this->view($t, $lineup)->listBlock($t, $lineup, $limit, $footer);
	}

	/**
		@return list<string> full tournament info for /info, split into messages when needed
	*/
	public function infoMessages(Tournament $t): array {
		$lineup = $this->tournaments->lineup($t->id);
		return $this->view($t, $lineup)->fullMessages($t, $lineup);
	}

	/**
		@return array<string, mixed>
	*/
	protected function markup(Tournament $t): array {
		if (!in_array($t->status, TournamentStatus::active(), true)) {
			return ['reply_markup' => ['inline_keyboard' => []]];
		}
		return DeepLink::button(self::BUTTON, DeepLink::tournament($this->bot->username, $t->id));
	}

	/**
		@return array<int, int> chat_id => message_id
	*/
	protected function announcementTargets(Tournament $t): array {
		if ($this->announcements !== null) {
			$stored = $this->announcements->listForTournament($t->id);
			if ($stored !== []) {
				return $stored;
			}
		}
		if ($t->announce_msg_id !== null && $t->chat_id !== null) {
			return [$t->chat_id => $t->announce_msg_id];
		}
		return [];
	}

	protected function view(Tournament $t, Lineup $lineup): AnnouncementView {
		$this->number($t);
		$ids = [$t->organizer_id];
		foreach (array_merge($lineup->main_teams, $lineup->reserve_teams, $lineup->forming_teams) as $entry) {
			foreach ($entry->members as $member) {
				$ids[] = $member->player_id;
			}
		}
		foreach (array_merge($lineup->main_solo, $lineup->reserve_solo) as $solo) {
			$ids[] = $solo->player_id;
		}
		$players = [];
		foreach (array_unique($ids) as $id) {
			$player = $this->players->find($id);
			if ($player instanceof Player) {
				$players[$id] = $player;
			}
		}
		return new AnnouncementView($this->bot->username, $players);
	}
}
