<?php

declare(strict_types=1);

namespace P1Bot\Ai;

/**
	Function tools offered to the model. Admin tools are offered to admins only (PHP re-checks anyway).
*/
class Tools {
	/**
		Owner only for now: scales are hidden from users (see AccessPolicy::LEVELS_OWNER_ONLY).
	*/
	public const OWNER_TOOLS = ['set_level', 'create_balance', 'show_balance', 'assign_nick'];

	public const ADMIN_TOOLS = [
		'create_tournament', 'edit_tournament', 'cancel_tournament', 'open_registration',
		'close_registration', 'set_max_players', 'set_level', 'create_balance', 'show_balance',
		'publish_tournament',
	];

	/**
		Tools that change a tournament lineup or settings (announcement must be refreshed).
	*/
	public const MUTATING = [
		'register_team', 'add_player', 'remove_player', 'replace_player', 'rename_team', 'transfer_captain',
		'withdraw_team', 'leave_team', 'join_solo', 'leave_solo', 'edit_tournament', 'cancel_tournament',
		'close_registration', 'set_max_players',
	];

	/**
		@return list<array<string, mixed>>
	*/
	public static function definitions(bool $is_admin, bool $is_owner = false): array {
		$tools = self::playerTools();
		if ($is_admin || $is_owner) {
			$tools = array_merge($tools, self::adminTools());
		}
		if ($is_owner) {
			$tools = array_merge(self::withOwnerParams($tools), self::ownerTools());
		}
		return $tools;
	}

	/**
		Owner only: create_tournament may pick a scale.

		@param list<array<string, mixed>> $tools
		@return list<array<string, mixed>>
	*/
	protected static function withOwnerParams(array $tools): array {
		foreach ($tools as $i => $tool) {
			if ($tool['name'] === 'create_tournament') {
				$properties = clone $tools[$i]['parameters']['properties'];
				$properties->balance_id = [
					'type' => 'integer',
					'description' => 'Номер шкалы уровней; по умолчанию основная шкала.',
				];
				$tools[$i]['parameters']['properties'] = $properties;
			}
		}
		return $tools;
	}

	/**
		@return list<array<string, mixed>>
	*/
	protected static function ownerTools(): array {
		return [
			self::fn('set_level', 'Поставить игроку уровень (только главный админ).', [
				'player' => ['type' => 'string', 'description' => '"@username".'],
				'level' => [
					'type' => 'string',
					'description' => '1–6 или название: начальный, начальный+, средний, средний+, высокий, высокий+.',
				],
				'balance_id' => ['type' => 'integer', 'description' => 'Номер шкалы; по умолчанию основная.'],
			], ['player', 'level']),
			self::fn('create_balance', 'Создать свою шкалу баланса.', [
				'name' => ['type' => 'string'],
				'based_on_balance_id' => ['type' => 'integer', 'description' => 'Унаследовать оценки из этой шкалы.'],
			], ['name']),
			self::fn('show_balance', 'Показать оценки игроков в шкале.', ['balance_id' => ['type' => 'integer']]),
			self::fn(
				'assign_nick',
				'Поставить игровой ник любому игроку (только главный админ); если ник занят — забрать.',
				[
					'player' => ['type' => 'string', 'description' => '"@username".'],
					'nick' => ['type' => 'string', 'description' => 'Ник в игре.'],
				],
				['player', 'nick'],
			),
		];
	}

	/**
		@return list<string>
	*/
	public static function names(bool $is_admin, bool $is_owner = false): array {
		return array_map(static function (array $tool): string {
			return (string) $tool['name'];
		}, self::definitions($is_admin, $is_owner));
	}

	/**
		@return list<array<string, mixed>>
	*/
	protected static function playerTools(): array {
		$tid = self::tournamentId();
		$team = [
			'type' => 'string',
			'description' => 'Название команды. Не указывай, если речь о команде, где автор сообщения капитан.',
		];
		$player = ['type' => 'string', 'description' => 'Игрок: "@username" или "я" для автора сообщения.'];

		return [
			self::fn(
				'list_tournaments',
				'Активные турниры текущей группы: номер, время по МСК, уровень, занятость.',
				[],
			),
			self::fn(
				'list_history',
				'Прошедшие турниры (завершённые и отменённые): последние 10, номер для /info N.',
				[],
			),
			self::fn(
				'tournament_info',
				'Подробности турнира: команды, соло-игроки, резерв.',
				['tournament_id' => $tid],
			),
			self::fn(
				'levels_help',
				'Справка по уровням игроков (1–6, баллы, синонимы) и по тому, как задаётся уровень турнира. Она отправится в этот чат автоматически.',
				[],
			),
			self::fn(
				'player_level',
				'Показать уровень игрока: себя (без player) или @username. Список всех выставленных — list_player_levels (только в личке).',
				['player' => ['type' => 'string', 'description' => '"@username" или пусто для автора.']],
			),
			self::fn(
				'list_player_levels',
				'Список игроков, которым уже поставили уровень (только в личном чате).',
				[],
			),
			self::fn(
				'show_nick',
				'Показать свой игровой ник или ник @username.',
				['player' => ['type' => 'string', 'description' => '"@username" или пусто для себя.']],
			),
			self::fn('register_team', 'Записать новую команду. Автор сообщения становится капитаном и входит в состав.', [
				'tournament_id' => $tid,
				'team_name' => ['type' => 'string', 'description' => 'Название команды.'],
				'players' => [
					'type' => 'array',
					'items' => ['type' => 'string'],
					'description' => 'Остальные игроки: "@username". Автора можно не указывать.',
				],
			], ['team_name']),
			self::fn(
				'add_player',
				'Капитан добавляет игрока в свою команду.',
				['tournament_id' => $tid, 'player' => $player, 'team_name' => $team],
				['player'],
			),
			self::fn(
				'remove_player',
				'Капитан убирает игрока из команды.',
				['tournament_id' => $tid, 'player' => $player, 'team_name' => $team],
				['player'],
			),
			self::fn('replace_player', 'Капитан меняет одного игрока на другого, команда сохраняет место в очереди.', [
				'tournament_id' => $tid,
				'out_player' => ['type' => 'string', 'description' => 'Кто выходит: "@username".'],
				'in_player' => ['type' => 'string', 'description' => 'Кто заходит: "@username".'],
				'team_name' => $team,
			], ['out_player', 'in_player']),
			self::fn('rename_team', 'Капитан переименовывает команду.', [
				'tournament_id' => $tid,
				'new_name' => ['type' => 'string', 'description' => 'Новое название.'],
				'team_name' => $team,
			], ['new_name']),
			self::fn(
				'transfer_captain',
				'Капитан передаёт капитанство игроку своей команды.',
				['tournament_id' => $tid, 'player' => $player, 'team_name' => $team],
				['player'],
			),
			self::fn(
				'withdraw_team',
				'Снять (распустить) команду с турнира.',
				['tournament_id' => $tid, 'team_name' => $team],
			),
			self::fn(
				'leave_team',
				'Автор сообщения (не капитан) сам выходит из своей команды.',
				['tournament_id' => $tid],
			),
			self::fn(
				'join_solo',
				'Записать автора сообщения соло ("хочу участвовать" без команды).',
				['tournament_id' => $tid],
			),
			self::fn('leave_solo', 'Снять соло-заявку автора сообщения.', ['tournament_id' => $tid]),
			self::fn('my_status', 'Где заявлен автор сообщения: команда, соло, основной состав или резерв.', []),
			self::fn('ask_clarification', 'Нужно уточнить у пользователя недостающие данные перед действием.', [
				'question' => ['type' => 'string', 'description' => 'Что именно уточнить.'],
			], ['question']),
		];
	}

	/**
		@return list<array<string, mixed>>
	*/
	protected static function adminTools(): array {
		$tid = self::tournamentId();
		$time = ['type' => 'string', 'description' => 'Дата и время по МСК: "23.11 20:00" или "2026-11-23 20:00".'];
		$level = [
			'type' => 'string',
			'description' => 'Уровень турнира (= максимальный уровень команды). Либо одно число — сумма баллов на команду, от team_size до team_size×6 (например "10" для троек), либо уровни всех игроков через запятую, ровно team_size штук: "средний, средний, высокий" или "3,3,5". Одно название уровня без числа не подходит. Обязательно.',
		];

		return [
			self::fn('create_tournament', 'Создать черновик турнира (не публикует). Вызывай, только когда известны название, дата и время по МСК, размер команды и уровень турнира. Организатор — автор сообщения. После вызова пользователю автоматически покажется карточка с вопросом «Публикуем или что-то поправить?».', [
				'title' => ['type' => 'string'],
				'starts_at_msk' => $time,
				'team_size' => ['type' => 'integer', 'minimum' => 2, 'maximum' => 4],
				'level' => $level,
				'max_players' => ['type' => 'integer', 'description' => 'Лимит участников, по умолчанию 24.'],
			], ['title', 'starts_at_msk', 'team_size', 'level']),
			self::fn('edit_tournament', 'Изменить турнир или черновик. Передавай только меняющиеся поля. Если меняешь размер команды, передай и уровень под новый размер. Для черновика карточка покажется снова.', [
				'tournament_id' => $tid,
				'title' => ['type' => 'string'],
				'starts_at_msk' => $time,
				'team_size' => ['type' => 'integer', 'minimum' => 2, 'maximum' => 4],
				'level' => $level,
				'max_players' => ['type' => 'integer'],
			]),
			self::fn('publish_tournament', 'Опубликовать черновик (открыть регистрацию и выложить анонс). Вызывай ТОЛЬКО если пользователь в ответ на карточку явно подтвердил: «да», «публикуем», «ок», «всё верно». Если есть правки или сомнения — не вызывай.', [
				'tournament_id' => $tid,
			]),
			self::fn('cancel_tournament', 'Отменить турнир.', ['tournament_id' => $tid]),
			self::fn(
				'open_registration',
				'Снова открыть закрытую регистрацию. Для черновика покажет карточку с вопросом «Публикуем или что-то поправить?» (сама публикация — только publish_tournament после явного «да»).',
				['tournament_id' => $tid],
			),
			self::fn('close_registration', 'Закрыть регистрацию.', ['tournament_id' => $tid]),
			self::fn(
				'set_max_players',
				'Изменить лимит участников турнира.',
				['tournament_id' => $tid, 'max_players' => ['type' => 'integer']],
				['max_players'],
			),
		];
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
