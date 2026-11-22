<?php

declare(strict_types=1);

namespace P1Bot\Ai;

/**
	Function tools offered to the model. The AI only shows information;
	actions go through commands, cards and Mini Apps. PHP re-checks anyway.
*/
class Tools {
	public const PLAYER_REF = 'Игрок как в сообщении: имя, игровой ник, @username, часть ника, или «я». Не переспрашивай, что это за ник — передай текст как есть.';

	/**
		@return list<array<string, mixed>>
	*/
	public static function definitions(bool $is_admin = false, bool $is_super = false): array {
		unset($is_admin, $is_super);
		$tid = self::tournamentId();
		$player = [
			'type' => 'string',
			'description' => self::PLAYER_REF . ' Пусто — автор.',
		];

		return [
			self::fn(
				'help',
				'Отправить справку по командам бота (тот же текст, что /help). Вызывай, когда просят помощь, что умеет бот или какие есть команды.',
				[],
			),
			self::fn(
				'list_tournaments',
				'Активные турниры: номер, время по МСК, уровень, занятость. Список с составами отправится в чат автоматически.',
				[],
			),
			self::fn(
				'list_history',
				'Прошедшие турниры (завершённые и отменённые): последние 10, номер для /tournaments N.',
				[],
			),
			self::fn(
				'tournament_info',
				'Подробности турнира: команды, соло-игроки, запас.',
				['tournament_id' => $tid],
			),
			self::fn(
				'levels_help',
				'Справка по уровням игроков (1–6, баллы, синонимы) и по тому, как считается сила команды. Она отправится в этот чат автоматически.',
				[],
			),
			self::fn(
				'player_level',
				'Показать уровень игрока: себя (без player) или любого названного игрока. Полный список — list_players; кому уже поставили уровень — list_player_levels.',
				['player' => $player],
			),
			self::fn(
				'list_player_levels',
				'Список игроков, которым уже поставили уровень (как /level all). Полный список всех игроков — list_players.',
				[],
			),
			self::fn(
				'list_players',
				'Полный список игроков, как команда /players: все известные игроки, сгруппированные по уровню. Список отправится в чат автоматически.',
				[],
			),
			self::fn(
				'show_nick',
				'Показать свой игровой ник или ник названного игрока. Поставить ник этим действием нельзя — подскажи /nick.',
				['player' => $player],
			),
			self::fn(
				'my_status',
				'Где заявлен автор сообщения: команда, соло, основной состав или запас.',
				[],
			),
			self::fn('ask_clarification', 'Нужно уточнить у пользователя недостающие данные, прежде чем показать информацию.', [
				'question' => ['type' => 'string', 'description' => 'Что именно уточнить.'],
			], ['question']),
		];
	}

	/**
		@return list<string>
	*/
	public static function names(bool $is_admin = false, bool $is_super = false): array {
		return array_map(static function (array $tool): string {
			return (string) $tool['name'];
		}, self::definitions($is_admin, $is_super));
	}

	/**
		@return array<string, mixed>
	*/
	protected static function tournamentId(): array {
		return [
			'type' => 'integer',
			'description' => 'Номер турнира в группе (№ из списка и контекста: 1, 2, 3...). Можно не указывать, если подходящий турнир один.',
		];
	}

	/**
		@param array<string, array<string, mixed>> $properties
		@param list<string> $required
		@return array<string, mixed>
	*/
	protected static function fn(string $name, string $description, array $properties, array $required = []): array {
		$parameters = ['type' => 'object', 'properties' => (object) $properties, 'additionalProperties' => false];
		if ($required !== []) {
			$parameters['required'] = $required;
		}
		return [
			'type' => 'function',
			'name' => $name,
			'description' => $description,
			'parameters' => $parameters,
			'strict' => false,
		];
	}
}
