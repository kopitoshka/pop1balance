<?php

declare(strict_types=1);

namespace P1Bot\View;

/**
	Fixed user-facing texts (HTML).
*/
class Texts {

	public static function greeting(string $bot_username): string {
		return "Всем привет! Я помогаю с турнирами по Population: One: собираю заявки команд и соло-игроков, "
			. "слежу за лимитами и напоминаю о старте.\n\n"
			. "Записаться, собрать команду или задать вопрос — в личке: кнопка под анонсом турнира или "
			. Html::e('@' . $bot_username) . ".\n"
			. 'Здесь, в группе, — анонсы и /tournaments.';
	}

	/**
		/help in a group: only commands that work here; management is in private.
	*/
	public static function groupHelp(string $bot_username): string {
		return "В этом чате я публикую анонсы турниров (они общие для всех групп) и напоминаю о старте. Команды здесь:\n\n"
			. "/tournaments — все активные турниры\n"
			. "/info 2 — состав турнира (номер — из /tournaments)\n"
			. "/levels — уровни игроков\n"
			. "/help — эта подсказка\n\n"
			. 'Записаться, управлять командой и турнирами — пишите мне в личку 👉 '
			. Html::e('@' . $bot_username);
	}

	/**
		/help in private: everything works here.
		Tournament numbers (№N) are global — the same in every group.
	*/
	public static function help(string $bot_username, bool $is_admin, bool $is_owner = false): string {
		$text = "Я помогаю записываться на турниры по Population: One. Пиши мне обычным языком, например:\n"
			. "• <i>какие турниры скоро?</i>\n"
			. "• <i>запиши команду Wolves на субботу: я, @vasya и @petya</i>\n"
			. "• <i>хочу участвовать, но без команды</i>\n"
			. ($is_admin ? "• <i>создай турнир на субботу в 20:00, тройки, уровень средний, средний, средний+</i>\n" : '')
			. "\n"
			. "Команды:\n"
			. "/start — турниры с кнопками записи\n"
			. "/tournaments — активные турниры\n"
			. "/info 2 — состав турнира: команды, соло и резерв (номер — из списка /tournaments, один на все группы)\n"
			. "/levels — уровни игроков и как задаётся уровень турнира\n"
			. '/help — эта подсказка';
		if ($is_admin || $is_owner) {
			$text .= "\n\nСоздание турниров и управление своим:\n"
				. "/new 23.11 20:00 | Название | 3 | 10 | 24 — создать турнир (дата и время по МСК | название | игроков в команде | уровень турнира | лимит участников). Я покажу карточку здесь, а после твоего «да» опубликую анонс во все группы\n"
				. "/edit 1 время 23.11 21:00 — изменить свой турнир (поля: название, время, размер, уровень, лимит)\n"
				. "/edit 1 уровень 10 или /edit 1 уровень средний, средний, высокий — уровень турнира (подробнее: /levels)\n"
				. "/open 5 — опубликовать свой черновик (покажу карточку и спрошу подтверждение) или снова открыть закрытую регистрацию\n"
				. "/close 5 — закрыть регистрацию на свой турнир\n"
				. "/cancel 5 — отменить свой турнир\n"
				. 'Править, открывать, закрывать и отменять турнир могут только его автор и главный админ.';
		}
		if ($is_owner) {
			// Owner only for now (see AccessPolicy::LEVELS_OWNER_ONLY); not shown to anyone else.
			$text .= "\n\nТолько для главного админа:\n"
				. "/level @user 4 — поставить уровень игроку (1–6 или название: начальный, начальный+, средний, средний+, высокий, высокий+; «слабый» = начальный, «танк» = высокий)\n"
				. "/balance — уровни, которые поставлены игрокам\n"
				. '/edit 1 баланс 2 — выбрать для турнира другой набор уровней игроков';
		}
		return $text;
	}

	/**
		Plain /start in private.
	*/
	public static function welcome(string $first_name): string {
		$name = trim($first_name) !== '' ? ', ' . Html::e(trim($first_name)) : '';
		return "Привет{$name}! 👋 Я помогаю с турнирами по Population: One. "
			. "Здесь можно записаться соло, собрать команду, задать вопрос — просто пиши обычным языком.\n"
			. 'Подсказка: /help';
	}

	/**
		/start t_N in private: greeting before the tournament view.
	*/
	public static function welcomeTournament(string $first_name): string {
		$name = trim($first_name) !== '' ? ', ' . Html::e(trim($first_name)) : '';
		return "Привет{$name}! 👋 Вот этот турнир. Кнопки ниже — или просто напиши, что нужно.";
	}

	/**
		/levels: the six player levels and how the tournament level works.
	*/
	public static function levels(): string {
		return "📊 <b>Уровни игроков</b>\n"
			. "1 — начальный (1 балл). Синонимы: слабый, базовый, начинающий\n"
			. "2 — начальный+ (2 балла). Также: слабый+, базовый+, начинающий+\n"
			. "3 — средний (3 балла)\n"
			. "4 — средний+ (4 балла)\n"
			. "5 — высокий (5 баллов). Синоним: танк\n"
			. "6 — высокий+ (6 баллов). Также: танк+\n"
			. "«+» можно писать словом: «средний плюс». Регистр не важен, небольшие опечатки я пойму. "
			. "Игрок, которому ещё не поставили уровень, считается средним (3).\n\n"
			. "⚖️ <b>Уровень турнира</b> — это максимальный уровень команды: сколько баллов в сумме могут набрать её игроки. "
			. "Команду примут, если сумма уровней её игроков не больше этого числа. Задать его можно так:\n"
			. "• одним числом — суммой баллов на команду, от размера команды до размера × 6 (в тройках от 3 до 18): "
			. "«10» в тройках — например, средний, средний и средний+;\n"
			. "• уровнями всех игроков, ровно по числу игроков в команде: «средний, средний, высокий» = 3 + 3 + 5 = 11 баллов "
			. "(можно цифрами: 3, 3, 5).\n"
			. "Подпись уровня турнира — среднее по команде с округлением: 10 баллов в тройках — «средний».\n\n"
			. "Примеры: /edit 1 уровень 10 · /edit 1 уровень средний, средний, высокий\n"
			. "Уровень игроку ставит админ: /level @user 4";
	}

	/**
		Usage help added when a tournament level was not understood.

		@param string $command 'edit' or 'new'
	*/
	public static function levelUsage(
		string $command = 'edit',
		?int $tournament_number = null,
		?int $team_size = null,
	): string {
		$n = $tournament_number ?? 1;
		$range = $team_size !== null
			? sprintf('от %d до %d для команды из %d', $team_size, $team_size * 6, $team_size)
			: 'от размера команды до размера × 6';
		if ($command === 'new') {
			$examples = "/new 23.11 20:00 | Кубок | 3 | 10 | 24\n/new 23.11 20:00 | Кубок | 3 | средний, средний, высокий | 24";
		} else {
			$examples = "/edit {$n} уровень 10\n/edit {$n} уровень средний, средний, высокий";
		}
		return "Уровень турнира можно указать так:\n"
			. "• одним числом — суммой баллов на команду ({$range});\n"
			. "• уровнями всех игроков через запятую, ровно по числу игроков (названия или цифры 1–6).\n"
			. "Например:\n{$examples}\n"
			. "Все уровни и баллы: /levels";
	}

	/**
		Reply to a message when the AI is not configured.
	*/
	public static function fallback(string $bot_username): string {
		return 'Я сейчас понимаю только команды и кнопки. Турниры: /start, подсказка: /help.';
	}

	public static function somethingWentWrong(): string {
		return 'Что-то пошло не так, попробуй ещё раз чуть позже.';
	}
}
