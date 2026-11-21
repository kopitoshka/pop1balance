<?php

declare(strict_types=1);

namespace P1Bot\Ai;

use P1Bot\Domain\TournamentService;

/**
	Function tools offered to the model. Admin tools are offered to admins only (PHP re-checks anyway).
*/
class Tools {
	public const PLAYER_REF = 'Игрок как в сообщении: имя, игровой ник, @username, часть ника, или «я». Не переспрашивай, что это за ник — передай текст как есть.';

	/**
		Owner only for now: scales are hidden from users (see AccessPolicy::LEVELS_OWNER_ONLY).
	*/
	public const OWNER_TOOLS = ['set_level', 'assign_nick'];

	public const ADMIN_TOOLS = [
		'create_tournament', 'edit_tournament', 'cancel_tournament', 'open_registration',
		'set_max_players', 'set_level', 'publish_tournament',
	];

	/**
		Tools that change a tournament lineup or settings (announcement must be refreshed).
	*/
	/**
		register_team, add_player, remove_player, replace_player, rename_team and transfer_captain
		only prepare the team form (nothing is saved until it is submitted), so they are not here.
	*/
	public const MUTATING = [
		'withdraw_team', 'leave_team', 'join_solo', 'leave_solo', 'edit_tournament', 'cancel_tournament',
		'set_max_players',
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
		return $tools;
	}

	/**
		@return list<array<string, mixed>>
	*/
	protected static function ownerTools(): array {
		return [
			self::fn('set_level', 'Поставить игроку уровень (только главный админ).', [
				'player' => ['type' => 'string', 'description' => self::PLAYER_REF],
				'level' => [
					'type' => 'string',
					'description' => '1–6 или название: начальный, начальный+, средний, средний+, высокий, высокий+.',
				],
			], ['player', 'level']),
			self::fn(
				'assign_nick',
				'Поставить игровой ник любому игроку (только главный админ); если ник занят — забрать.',
				[
					'player' => ['type' => 'string', 'description' => self::PLAYER_REF],
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
		$player = ['type' => 'string', 'description' => self::PLAYER_REF];

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
				'Подробности турнира: команды, соло-игроки, запас.',
				['tournament_id' => $tid],
			),
			self::fn(
				'levels_help',
				'Справка по уровням игроков (1–6, баллы, синонимы) и по тому, как задаётся уровень турнира. Она отправится в этот чат автоматически.',
				[],
			),
			self::fn(
				'player_level',
				'Показать уровень игрока: себя (без player) или любого названного игрока. Список всех выставленных — list_player_levels (только в личке).',
				['player' => ['type' => 'string', 'description' => self::PLAYER_REF . ' Пусто — автор.']],
			),
			self::fn(
				'list_player_levels',
				'Список игроков, которым уже поставили уровень (только в личном чате).',
				[],
			),
			self::fn(
				'set_nick',
				'Поставить или сменить игровой ник автора сообщения (Population: One IGN).',
				['nick' => ['type' => 'string', 'description' => 'Ник в игре (2–32 символа, пробелы можно).']],
				['nick'],
			),
			self::fn(
				'show_nick',
				'Показать свой игровой ник или ник названного игрока.',
				['player' => ['type' => 'string', 'description' => self::PLAYER_REF . ' Пусто — автор.']],
			),
			self::fn('register_team', 'Подготовить форму новой команды (сама команда не записывается, пока человек не нажмёт кнопку в форме). Автор — капитан и в составе. Организатор/владелец может указать другого капитана. Вызывай сразу, как только человек хочет записать команду, даже без названия и без полного состава; игроков передавай теми словами, что сказал человек («я», имя, ник, часть ника), не прося @username. Ответ и кнопку формы бот отправит сам.', [
				'tournament_id' => $tid,
				'team_name' => ['type' => 'string', 'description' => 'Название команды, если его назвали.'],
				'players' => [
					'type' => 'array',
					'items' => ['type' => 'string'],
					'description' => 'Остальные игроки — как их назвал человек (имя, игровой ник, @username, часть ника). Автора/капитана можно не указывать. Не переспрашивай ники, передай как есть: кого не найду, человек выберет в форме.',
				],
				'captain' => [
					'type' => 'string',
					'description' => 'Капитан — как его назвал человек. Только для организатора/владельца; иначе автор сам капитан.',
				],
			], []),
			self::fn(
				'add_player',
				'Открыть форму команды с предложенным новым игроком (сохранит капитан или организатор в форме).',
				['tournament_id' => $tid, 'player' => $player, 'team_name' => $team],
				['player'],
			),
			self::fn(
				'remove_player',
				'Открыть форму команды без названного игрока (сохранит капитан или организатор в форме).',
				['tournament_id' => $tid, 'player' => $player, 'team_name' => $team],
				['player'],
			),
			self::fn('replace_player', 'Открыть форму команды с заменой одного игрока на другого; при сохранении полная команда сохраняет место в очереди.', [
				'tournament_id' => $tid,
				'out_player' => ['type' => 'string', 'description' => 'Кто выходит: ' . self::PLAYER_REF],
				'in_player' => ['type' => 'string', 'description' => 'Кто заходит: ' . self::PLAYER_REF],
				'team_name' => $team,
			], ['out_player', 'in_player']),
			self::fn('rename_team', 'Открыть форму команды с новым названием (сохранит капитан или организатор в форме).', [
				'tournament_id' => $tid,
				'new_name' => ['type' => 'string', 'description' => 'Новое название.'],
				'team_name' => $team,
			], ['new_name']),
			self::fn(
				'transfer_captain',
				'Открыть форму команды, где капитаном отмечен названный игрок этой команды.',
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
			self::fn('my_status', 'Где заявлен автор сообщения: команда, соло, основной состав или запас.', []),
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
			'description' => 'Уровень турнира (= максимальный уровень команды, с бонусом за пары игроков 4+). Либо одно число — сумма баллов на команду, от размера команды до силы команды из высоких+ (в тройках от 3 до 27, например "10"), либо уровни всех игроков через запятую, ровно team_size штук: "средний, средний, высокий" или "3,3,5". Одно название уровня без числа подходит только при team_size = 1. Обязательно.',
		];

		return [
			self::fn('create_tournament', 'Создать черновик турнира (не публикует). Вызывай, только когда известны название, дата и время по МСК, размер команды и уровень турнира. Организатор — автор сообщения. После вызова пользователю автоматически покажется карточка с вопросом «Публикуем или что-то поправить?».', [
				'title' => ['type' => 'string'],
				'starts_at_msk' => $time,
				'team_size' => ['type' => 'integer', 'minimum' => TournamentService::MIN_TEAM_SIZE, 'maximum' => TournamentService::MAX_TEAM_SIZE],
				'level' => $level,
				'max_players' => ['type' => 'integer', 'description' => 'Лимит участников, по умолчанию 24.'],
			], ['title', 'starts_at_msk', 'team_size', 'level']),
			self::fn('edit_tournament', 'Изменить турнир или черновик. Передавай только меняющиеся поля. Если меняешь размер команды, передай и уровень под новый размер. Для черновика карточка покажется снова.', [
				'tournament_id' => $tid,
				'title' => ['type' => 'string'],
				'starts_at_msk' => $time,
				'team_size' => ['type' => 'integer', 'minimum' => TournamentService::MIN_TEAM_SIZE, 'maximum' => TournamentService::MAX_TEAM_SIZE],
				'level' => $level,
				'max_players' => ['type' => 'integer'],
			]),
			self::fn('publish_tournament', 'Опубликовать черновик (открыть регистрацию и выложить анонс). Вызывай ТОЛЬКО если пользователь в ответ на карточку явно подтвердил: «да», «публикуем», «ок», «всё верно». Если есть правки или сомнения — не вызывай.', [
				'tournament_id' => $tid,
			]),
			self::fn('cancel_tournament', 'Отменить опубликованный турнир или удалить черновик навсегда.', ['tournament_id' => $tid]),
			self::fn(
				'open_registration',
				'Снова открыть закрытую регистрацию. Для черновика покажет карточку с вопросом «Публикуем или что-то поправить?» (сама публикация — только publish_tournament после явного «да»).',
				['tournament_id' => $tid],
			),
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
