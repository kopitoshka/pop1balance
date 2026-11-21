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
		unset($bot_username);
		return "В этом чате я публикую анонсы турниров (они общие для всех групп) и напоминаю о старте. "
			. "Записаться — кнопкой под анонсом.\n\n"
			. "Команды здесь:\n"
			. "/tournaments — все активные турниры\n"
			. "/info 2 — состав турнира (номер — из /tournaments)\n"
			. "/level — свой уровень (или /level @ник — чужой)\n"
			. "/players — все игроки: уровень и игровой ник\n"
			. "/help — эта подсказка\n\n"
			. Messages::GROUP_HELP_HINT;
	}

	/**
		/help in private: commands, then how levels work (the old /levels reference).
		Tournament numbers (№N) are global — the same in every group.
	*/
	public static function help(string $bot_username, bool $is_admin, bool $is_owner = false): string {
		unset($bot_username);
		$text = "Я помогаю записываться на турниры по Population: One. Записаться соло, собрать "
			. "или поправить команду — кнопками на карточке турнира (/tournaments).\n\n"
			. "Команды:\n"
			. "/tournaments — активные турниры (кнопка открывает состав)\n"
			. "/history — прошедшие турниры (последние 10)\n"
			. "/team — карточка твоей команды на ближайших турнирах\n"
			. "/players — все игроки: уровень и игровой ник\n"
			. "/level — свой уровень; /level @ник — чужой; /level all — у кого уже поставлен\n"
			. "/nick — свой игровой ник; <code>/nick игровой_ник</code> — поставить или сменить\n"
			. "/addplayer — добавить игроков через выбор в Telegram (до 10 за раз)\n"
			. '/help — эта подсказка';
		if ($is_admin || $is_owner) {
			$text .= "\n\nСоздание турниров и управление своим:\n"
				. "/create_tournament — создать турнир через форму\n"
				. "<code>/addplayer @username НикВИгре</code> — добавить игрока, который ещё не писал мне "
				. "(ник можно не указывать); <code>/addplayer НикВИгре</code> — игрока без Telegram. "
				. "Таких можно ставить в составы, но сообщений от меня они не получают. "
				. "Капитаном такого игрока может поставить только организатор турнира или главный админ. "
				. "По @username привяжу Telegram, когда человек сам мне напишет.\n"
				. "Черновик правится и удаляется кнопками на его карточке («✏️ Поправить», «🗑 Удалить»), "
				. "опубликованный турнир — кнопками «✏️ Изменить турнир» и «❌ Отменить турнир» на карточке турнира. "
				. 'Это могут только автор турнира и главный админ.';
		}
		if ($is_owner) {
			// Owner only for now (see AccessPolicy::LEVELS_OWNER_ONLY); not shown to anyone else.
			$text .= "\n\nТолько для главного админа:\n"
				. "/level @user 4 — поставить уровень игроку (1–6 или название: начальный, начальный+, средний, средний+, высокий, высокий+; «танк» = высокий)\n"
				. "<code>/level НикВИгре 4</code> — то же по игровому нику (и для игроков без Telegram)\n"
				. "/nick @user НикВИгре — поставить игровой ник любому (даже если занят); "
				. "<code>/nick \"Старый ник\" Новый ник</code> — сменить ник игроку без Telegram\n"
				. "/players_import — добавить или обновить игроков списком (ник или @username, в конце уровень 1–6). "
				. "Без аргументов жду список следующим сообщением (или отправь его с командой)";
		}
		return $text . "\n\n" . self::levels();
	}

	/**
		Plain /start in private.
	*/
	public static function welcome(string $first_name): string {
		$name = trim($first_name) !== '' ? ', ' . Html::e(trim($first_name)) : '';
		return "Привет{$name}! 👋 Я помогаю с турнирами по Population: One. "
			. "Записаться соло или собрать команду можно кнопками на карточке турнира.\n"
			. 'Подсказка: /help';
	}

	/**
		/start t_N in private: greeting before the tournament view.
	*/
	public static function welcomeTournament(string $first_name): string {
		$name = trim($first_name) !== '' ? ', ' . Html::e(trim($first_name)) : '';
		return "Привет{$name}! 👋 Вот этот турнир, кнопки — ниже.";
	}

	/**
		How levels work: the player scale 1–6, the tournament level, the default level.
		Part of /help (the old /levels reference); the AI levels_help reply sends the same text.
	*/
	public static function levels(): string {
		return "📊 <b>Уровни игроков</b>\n"
			. "1 — начальный (1 балл). Синонимы: базовый, начинающий\n"
			. "2 — начальный+ (2 балла). Также: базовый+, начинающий+\n"
			. "3 — средний (3 балла)\n"
			. "4 — средний+ (4 балла)\n"
			. "5 — высокий (5 баллов). Синоним: танк\n"
			. "6 — высокий+ (6 баллов). Также: танк+\n"
			. "Игрок, которому ещё не поставили уровень, считается средним (3).\n\n"
			. "⚖️ <b>Уровень турнира</b> — это максимальная сумма баллов команды "
			. "(баллы = уровни игроков 1–6). Команду примут, если сумма не больше этого числа.\n"
			. "К сумме я добавляю бонус за каждую пару игроков 4-го уровня и выше: уровень более слабого минус 3. "
			. "Так 5+4+2 даёт 12, а 5+3+3 остаётся 11.\n"
			. "Его задают в форме турнира:\n"
			. "• «Суммой» — максимум баллов на команду (в тройках от 3 до 27), "
			. "например 10 — средний, средний и средний+;\n"
			. "• «По игрокам» — уровни всех игроков самой сильной допустимой команды: "
			. "средний, средний, высокий = 3+3+5 = 11.\n"
			. "Подпись уровня турнира — среднее по команде с округлением: 10 баллов в тройках — «средний».";
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
