<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Logger;
use P1Bot\Telegram\BotIdentity;
use P1Bot\Telegram\TelegramApi;
use P1Bot\View\DeepLink;
use P1Bot\View\Html;
use P1Bot\View\Messages;
use Throwable;

/**
	After a team is disbanded: DM every other member who started the bot (dm_ok).
*/
class TeamDisbandNotifier {

	public function __construct(
		protected TelegramApi $api,
		protected BotIdentity $bot,
		protected PlayerRepository $players,
		protected Logger $logger,
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
		foreach ($disband['member_ids'] as $player_id) {
			if ($player_id === $actor_id) {
				continue;
			}
			$player = $this->players->find($player_id);
			if ($player === null || $player->tg_user_id === null || !$this->players->dmOk($player_id)) {
				continue;
			}
			try {
				$this->api->sendMessage($player->tg_user_id, Html::e($text), null, $markup);
			} catch (Throwable $e) {
				$this->logger->exception($e, sprintf(
					'disband DM to player %d (tournament %d)',
					$player_id,
					$disband['tournament_id'],
				));
			}
		}
	}

	/**
		Member left/cancelled: DM captain when dm_ok (not when actor is captain).
	*/
	public function notifyMemberLeft(
		\P1Bot\Domain\Model\Player $actor,
		int $captain_id,
		string $team_name,
		int $tournament_id,
	): void {
		if ($actor->id === $captain_id) {
			return;
		}
		$captain = $this->players->find($captain_id);
		if ($captain === null || $captain->tg_user_id === null || !$this->players->dmOk($captain_id)) {
			return;
		}
		try {
			$this->api->sendMessage(
				$captain->tg_user_id,
				Html::e(Messages::memberLeftTeam($actor->mention(), $team_name, $tournament_id)),
			);
		} catch (Throwable $e) {
			$this->logger->exception($e, sprintf(
				'member-left DM to captain %d (tournament %d)',
				$captain_id,
				$tournament_id,
			));
		}
	}
}
