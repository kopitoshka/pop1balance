<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Domain\AccessPolicy;
use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Model\Player;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\SuLog;
use P1Bot\Telegram\CommandMenu;
use P1Bot\Telegram\TelegramApi;
use P1Bot\View\Html;
use Throwable;

/**
	Superadmin mode (/su). The right «суперадмин» is given by the bot owner (/su grant, or the checkbox
	on the player card); the mode is switched with /su on and /su off and stays until it is switched
	off. With the mode on a player has every bot-owner ability (AccessPolicy::isSuper).

	Who may do what:
	- /su on, /su off, /su: the bot owner and everyone with the right.
	- /su grant, /su revoke, /su list (and the card checkbox): the bot owner always, even with the mode
	  off; others with the right only while their own mode is on.
	- Revoking also turns the mode of that player off. The bot owner's own right cannot be revoked.
	Every on/off/grant/revoke goes to the su log. Menus of the affected private chats are refreshed.
*/
class SuperAdmin {
	public const MODE_OFF_FIRST = 'Сначала включи режим суперадмина: /su on.';
	public const OWNER_KEEPS = 'Право главного админа забрать нельзя.';

	public function __construct(
		protected AccessPolicy $access,
		protected PlayerRepository $players,
		protected TelegramApi $api,
		protected ?SuLog $log = null,
	) {
	}

	/**
		Does the player see /su at all (help, menu)? Without the right /su is an unknown command.
	*/
	public function hasRight(Player $player): bool {
		return $this->access->hasSuRight($player->id);
	}

	/**
		«Режим суперадмина: включён.» and what the player may type.
	*/
	public function status(Player $actor): string {
		$lines = [
			'Режим суперадмина: ' . ($this->access->suModeOn($actor->id) ? 'включён' : 'выключен') . '.',
			'',
			'Параметры:',
			'/su on — включить режим',
			'/su off — выключить режим',
		];
		if ($this->access->canManageSu($actor->id)) {
			$lines[] = '/su grant @ник — дать право суперадмина';
			$lines[] = '/su revoke @ник — забрать право';
			$lines[] = '/su list — у кого есть право';
		}
		return implode("\n", $lines);
	}

	/**
		Short «what you can type» for a wrong parameter.
	*/
	public function usage(Player $actor): string {
		$params = $this->access->canManageSu($actor->id)
			? 'on | off | grant @ник | revoke @ник | list'
			: 'on | off';
		return 'Не поняла параметр. Можно так: /su ' . $params . '.';
	}

	public function setMode(Player $actor, bool $on): string {
		$this->assertHasRight($actor);
		$word = $on ? 'включён' : 'выключен';
		if ($this->access->suModeOn($actor->id) === $on) {
			return 'Режим суперадмина уже ' . $word . '.';
		}
		$this->players->setSuMode($actor->id, $on);
		$this->log?->write($on ? 'on' : 'off', $actor);
		$this->refreshMenu($actor->id);
		return ($on ? 'Включила' : 'Выключила') . ' режим суперадмина.'
			. ($on ? ' Теперь тебе доступно всё, что может главный админ.' : ' Теперь я вижу в тебе обычного игрока.');
	}

	public function grant(Player $actor, Player $target): string {
		$this->assertCanManage($actor);
		$this->assertTelegram($target);
		if ($this->access->isBotOwner($target->id)) {
			return 'У главного админа это право есть всегда.';
		}
		if ($target->can_su) {
			return sprintf('У %s право суперадмина уже есть.', Html::e($target->plainLabel()));
		}
		$this->players->setSuRight($target->id, true);
		$this->log?->write('grant', $actor, $target);
		$this->refreshMenu($target->id);
		return sprintf(
			'Дала право суперадмина игроку %s. Режим он включает сам: /su on.',
			Html::e($target->plainLabel()),
		);
	}

	public function revoke(Player $actor, Player $target): string {
		$this->assertCanManage($actor);
		if ($this->access->isBotOwner($target->id)) {
			throw new PermissionDenied(self::OWNER_KEEPS);
		}
		if (!$target->can_su) {
			return sprintf('У %s и так нет права суперадмина.', Html::e($target->plainLabel()));
		}
		$this->players->setSuRight($target->id, false);
		$this->log?->write('revoke', $actor, $target);
		$this->refreshMenu($target->id);
		return sprintf(
			'Забрала право суперадмина у %s и выключила ему режим.',
			Html::e($target->plainLabel()),
		);
	}

	public function listRights(Player $actor): string {
		$this->assertCanManage($actor);
		$rows = [];
		$owner = $this->ownerPlayer();
		if ($owner !== null) {
			$rows[] = [$owner, 'главный админ'];
		}
		foreach ($this->players->listWithSuRight() as $player) {
			if ($owner === null || $player->id !== $owner->id) {
				$rows[] = [$player, ''];
			}
		}
		if ($rows === []) {
			return 'Пока ни у кого нет права суперадмина.';
		}
		$lines = ['Суперадмины:'];
		foreach ($rows as [$player, $note]) {
			$lines[] = sprintf(
				'• %s — %s%s',
				Html::e($player->plainLabel()),
				$this->access->suModeOn($player->id) ? 'режим включён' : 'режим выключен',
				$note !== '' ? ', ' . $note : '',
			);
		}
		return implode("\n", $lines);
	}

	/**
		The player card checkbox «Может включать /su»: same rule and effect as grant / revoke.

		@return bool true when the right changed
	*/
	public function setRight(Player $actor, Player $target, bool $can_su): bool {
		$this->assertCanManage($actor);
		if ($this->access->isBotOwner($target->id)) {
			if (!$can_su) {
				throw new PermissionDenied(self::OWNER_KEEPS);
			}
			return false;
		}
		if ($target->can_su === $can_su) {
			return false;
		}
		if ($can_su) {
			$this->assertTelegram($target);
			$this->players->setSuRight($target->id, true);
			$this->log?->write('grant', $actor, $target);
		} else {
			$this->players->setSuRight($target->id, false);
			$this->log?->write('revoke', $actor, $target);
		}
		$this->refreshMenu($target->id);
		return true;
	}

	/**
		The private-chat menu of this player: /su (and the owner commands) as their rights are now.
		Telegram refuses a menu for a chat that never started the bot: that is fine, help still works.
	*/
	public function refreshMenu(int $player_id): void {
		$player = $this->players->find($player_id);
		if ($player === null || $player->tg_user_id === null) {
			return;
		}
		$scope = CommandMenu::chatScope($player->tg_user_id);
		try {
			if (!$this->access->hasSuRight($player->id)) {
				$this->api->deleteMyCommands($scope);
				return;
			}
			$this->api->setMyCommands(
				CommandMenu::forPrivateChat($this->access->isSuper($player->id), true),
				$scope,
			);
		} catch (Throwable $e) {
			unset($e);
		}
	}

	protected function assertHasRight(Player $actor): void {
		if (!$this->access->hasSuRight($actor->id)) {
			throw new PermissionDenied(AccessPolicy::OWNER_ONLY);
		}
	}

	protected function assertCanManage(Player $actor): void {
		$this->assertHasRight($actor);
		if (!$this->access->canManageSu($actor->id)) {
			throw new PermissionDenied(self::MODE_OFF_FIRST);
		}
	}

	protected function assertTelegram(Player $target): void {
		if ($target->tg_user_id === null || $target->isDeleted()) {
			throw new ValidationError(
				'У этого игрока нет Telegram: право привязывается к аккаунту. '
				. 'Пусть он сначала напишет мне, и повтори.',
			);
		}
	}

	protected function ownerPlayer(): ?Player {
		return $this->access->botOwnerPlayer();
	}
}
