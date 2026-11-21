<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Domain\Model\Player;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Repository\TournamentRepository;
use P1Bot\Support\Logger;
use P1Bot\Telegram\BotIdentity;
use P1Bot\Telegram\TelegramApi;
use P1Bot\View\DeepLink;
use P1Bot\View\Html;
use P1Bot\View\Messages;
use Throwable;

/**
	After a team is disbanded: DM every other member who started the bot (dm_ok).
	Players without Telegram are skipped silently (no exception).
*/
class TeamDisbandNotifier {

	public function __construct(
		protected TelegramApi $api,
		protected BotIdentity $bot,
		protected PlayerRepository $players,
		protected Logger $logger,
		protected TournamentRepository $tournaments,
	) {
	}

	/**
		@param array{team_name: string, tournament_id: int, tournament_title: string, member_ids: list<int>, by_captain: bool} $disband
	*/
	public function notify(int $actor_id, array $disband): void {
		$text = $disband['by_captain']
			? Messages::captainDisbanded($disband['team_name'], $disband['tournament_title'])
			: Messages::organizerDisbanded($disband['team_name'], $disband['tournament_title']);
		$markup = DeepLink::button(
			Messages::GROUP_REDIRECT_BUTTON,
			DeepLink::tournament($this->bot->username, $disband['tournament_id']),
		);
		$html = Html::e($text);
		foreach ($disband['member_ids'] as $player_id) {
			if ($player_id === $actor_id) {
				continue;
			}
			$this->dmPlayer(
				$player_id,
				$html,
				$markup,
				sprintf('disband DM to player %d (tournament %d)', $player_id, $disband['tournament_id']),
			);
		}
	}

	/**
		Member left/cancelled: DM the captain when they can receive messages. If the captain has
		no Telegram (or has not started the bot), send the same text to the tournament organizer.
		Never throws; unreachable recipients are skipped.
	*/
	public function notifyMemberLeft(
		Player $actor,
		int $captain_id,
		string $team_name,
		int $tournament_id,
	): void {
		if ($actor->id === $captain_id) {
			return;
		}
		$text = Html::e(Messages::memberLeftTeam($actor->mention(), $team_name, $tournament_id));
		if ($this->dmPlayer(
			$captain_id,
			$text,
			null,
			sprintf('member-left DM to captain %d (tournament %d)', $captain_id, $tournament_id),
		)) {
			return;
		}
		$t = $this->tournaments->find($tournament_id);
		if ($t === null || $t->organizer_id === $actor->id || $t->organizer_id === $captain_id) {
			return;
		}
		$this->dmPlayer(
			$t->organizer_id,
			$text,
			null,
			sprintf(
				'member-left DM to organizer %d (tournament %d, captain unreachable)',
				$t->organizer_id,
				$tournament_id,
			),
		);
	}

	/**
		@return bool true if a message was accepted by the API
	*/
	protected function dmPlayer(int $player_id, string $html, ?array $markup, string $log): bool {
		$player = $this->players->find($player_id);
		if ($player === null || $player->tg_user_id === null || !$this->players->dmOk($player_id)) {
			return false;
		}
		try {
			$options = $markup !== null ? $markup : [];
			$this->api->sendMessage($player->tg_user_id, $html, null, $options);
			return true;
		} catch (Throwable $e) {
			$this->logger->exception($e, $log);
			return false;
		}
	}
}
