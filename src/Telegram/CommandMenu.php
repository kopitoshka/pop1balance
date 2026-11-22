<?php

declare(strict_types=1);

namespace P1Bot\Telegram;

/**
	The command menus (the «/» button) per Telegram scope. bin/set_webhook.php sets the common,
	group and private menus; a private chat of a superadmin gets its own menu (scope chat):
	/addplayer and /players_import while the superadmin mode is on, /su for everyone who has the right.
*/
class CommandMenu {

	/**
		@return list<array{command: string, description: string}> default menu and all group chats
	*/
	public static function common(): array {
		return [
			['command' => 'tournaments', 'description' => 'Активные турниры'],
			['command' => 'level', 'description' => 'Свой или чужой уровень'],
			['command' => 'players', 'description' => 'Все игроки: уровень и ник'],
			['command' => 'help', 'description' => 'Справка по командам'],
			['command' => 'help_level', 'description' => 'Как считаются уровни и сила команды'],
		];
	}

	/**
		Private chats. No /start (Telegram has Start); no /history|/info in the menu.

		@return list<array{command: string, description: string}>
	*/
	public static function privateChats(): array {
		return [
			['command' => 'tournaments', 'description' => 'Активные турниры'],
			['command' => 'create_tournament', 'description' => 'Создать турнир через форму'],
			['command' => 'team', 'description' => 'Карточка твоей команды'],
			['command' => 'level', 'description' => 'Свой или чужой уровень'],
			['command' => 'nick', 'description' => 'Свой игровой ник'],
			['command' => 'players', 'description' => 'Все игроки: уровень и ник'],
			['command' => 'help', 'description' => 'Справка по командам'],
			['command' => 'help_level', 'description' => 'Как считаются уровни и сила команды'],
		];
	}

	/**
		The private menu of one person.

		@param bool $is_super the superadmin mode is on: owner commands are shown
		@param bool $can_su the person has the /su right (the bot owner always has it)
		@return list<array{command: string, description: string}>
	*/
	public static function forPrivateChat(bool $is_super, bool $can_su): array {
		$menu = self::privateChats();
		$extra = [];
		if ($is_super) {
			$extra[] = ['command' => 'addplayer', 'description' => 'Добавить игроков из контактов Telegram'];
			$extra[] = ['command' => 'players_import', 'description' => 'Добавить игроков списком с уровнями'];
		}
		if ($can_su) {
			$extra[] = ['command' => 'su', 'description' => 'Режим суперадмина: on | off'];
		}
		// Before /help_level, the last item.
		array_splice($menu, count($menu) - 1, 0, $extra);
		return $menu;
	}

	/**
		Scope of the private chat with a Telegram user (the chat id of a private chat is the user id).

		@return array{type: string, chat_id: int}
	*/
	public static function chatScope(int $tg_user_id): array {
		return ['type' => 'chat', 'chat_id' => $tg_user_id];
	}
}
