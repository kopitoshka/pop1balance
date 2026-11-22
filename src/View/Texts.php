<?php

declare(strict_types=1);

namespace P1Bot\View;

use P1Bot\Domain\Levels;
use P1Bot\Support\RuPlural;

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
		/help in a group: commands that work here, plus the same /su and superadmin
		sections as in private (so the visibility rules are the same in both places).
	*/
	public static function groupHelp(
		string $bot_username,
		bool $is_super = false,
		bool $can_su = false,
		bool $can_manage_su = false,
	): string {
		unset($bot_username);
		$text = "В этом чате я публикую анонсы турниров (они общие для всех групп) и напоминаю о старте. "
			. "Записаться — кнопкой под анонсом.\n\n"
			. "<b>Команды здесь</b>\n"
			. "/tournaments — все активные турниры\n"
			. "/tournaments 2 — состав турнира (номер — из списка)\n"
			. "/level — свой уровень (или /level @ник — чужой)\n"
			. "/players — все игроки: уровень и игровой ник\n"
			. "/help — эта подсказка\n"
			. "/help_level — Как считаются уровни и сила команды";
		$text .= self::suCommandLine($can_su);
		$text .= "\n\nПоставить свой ник можно у меня в личке.\n"
			. Messages::GROUP_HELP_HINT;
		return $text . self::superadminHelpBlocks($is_super, $can_manage_su);
	}

	/**
		/help in private: commands, including /help_level.
		Tournament numbers (№N) are global — the same in every group.
	*/
	public static function help(
		string $bot_username,
		bool $is_admin,
		bool $is_super = false,
		bool $can_su = false,
		bool $can_manage_su = false,
	): string {
		unset($bot_username);
		$text = "Я помогаю записываться на турниры по Population: One. Записаться соло, собрать "
			. "или поправить команду — кнопками на карточке турнира (/tournaments).\n\n"
			. "<b>Команды</b>\n"
			. "/tournaments — активные турниры (кнопка открывает состав)\n"
			. "/tournaments 2 — состав одного турнира (номер — из списка)\n"
			. "/history — прошедшие турниры (последние 10)\n"
			. "/team — карточка твоей команды на ближайших турнирах\n"
			. "/players — все игроки: уровень и игровой ник\n"
			. "/level — свой уровень; /level @ник — чужой; /level all — у кого уже поставлен\n"
			. "/nick — свой игровой ник; <code>/nick игровой_ник</code> — поставить или сменить\n"
			. "/addplayer — добавить игроков через выбор в Telegram (до 10 за раз)\n"
			. "/help — эта подсказка\n"
			. '/help_level — Как считаются уровни и сила команды';
		$text .= self::suCommandLine($can_su);
		if ($is_admin || $is_super) {
			$text .= "\n\n<b>Управление турнирами</b>\n"
				. "/create_tournament — создать турнир через форму\n"
				. "<code>/addplayer @username НикВИгре</code> — добавить игрока, который ещё не писал мне "
				. "(ник можно не указывать); <code>/addplayer НикВИгре</code> — игрока без Telegram. "
				. 'Таких можно ставить в составы, но сообщений от меня они не получают.';
		}
		return $text . self::superadminHelpBlocks($is_super, $can_manage_su);
	}

	/**
		The /su line in /help: only for those who have the right (the bot owner included),
		whether or not the mode is on. Everyone else never sees /su.
	*/
	protected static function suCommandLine(bool $can_su): string {
		return $can_su
			? "\n/su — режим суперадмина: <code>/su on</code>, <code>/su off</code>; без параметра — статус"
			: '';
	}

	/**
		Superadmin command help only while the mode is on (isSuper). Grant / revoke / list
		follow canManageSu: the bot owner always, other holders only with the mode on.
	*/
	protected static function superadminHelpBlocks(bool $is_super, bool $can_manage_su): string {
		$text = '';
		if ($is_super) {
			$text .= "\n\n<b>Только для главного админа</b>\n"
				. '/level @user 4 — поставить уровень игроку (1–6 или название: '
				. implode(', ', Levels::all())
				. '; старые имена вроде «средний+» тоже подойдут)\n'
				. "<code>/level НикВИгре 4</code> — то же по игровому нику (и для игроков без Telegram)\n"
				. "/nick @user НикВИгре — поставить игровой ник любому (даже если занят); "
				. "<code>/nick \"Старый ник\" Новый ник</code> — сменить ник игроку без Telegram\n"
				. "/players_import — добавить или обновить игроков списком (ник или @username, в конце уровень 1–6). "
				. "Без аргументов жду список следующим сообщением (или отправь его с командой)";
		}
		if ($can_manage_su) {
			$text .= "\n\n<b>Суперадмины</b>\n"
				. "/su grant @ник — дать право суперадмина\n"
				. "/su revoke @ник — забрать право (и выключить ему режим)\n"
				. "/su list — у кого есть право\n"
				. 'То же — галочка «Может включать /su» на карточке игрока (из /players).';
		}
		return $text;
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
		$lines = ["📊 <b>Уровни игроков</b>"];
		foreach (Levels::all() as $level => $name) {
			$lines[] = sprintf(
				'%d — %s (%d %s)',
				$level,
				$name,
				$level,
				RuPlural::points($level),
			);
		}
		$lines[] = sprintf(
			'Игрок, которому ещё не поставили уровень, считается %s (%d).',
			Levels::name(Levels::DEFAULT),
			Levels::DEFAULT,
		);
		$lines[] = '';
		$lines[] = '⚖️ <b>Макс. уровень</b> турнира — это максимальная сумма баллов команды '
			. '(баллы = уровни игроков 1–6). В анонсе это строка «Макс. уровень: 11». '
			. 'Команду примут, если сумма не больше этого числа.';
		$lines[] = 'К сумме я добавляю бонус за каждую пару игроков 4-го уровня ('
			. Levels::name(4) . ') и выше: уровень более слабого минус 3. '
			. 'Так 5+4+2 даёт 12, а 5+3+3 остаётся 11.';
		$lines[] = 'Старые названия (начальный, средний, высокий и с «+») и синонимы '
			. '(базовый, начинающий, танк) я тоже понимаю.';
		return implode("\n", $lines);
	}

	/**
		Reply to a message when the AI is not configured.
	*/
	public static function fallback(string $bot_username): string {
		return 'Я сейчас понимаю только команды и кнопки. Турниры: /tournaments, подсказка: /help.';
	}

	public static function somethingWentWrong(): string {
		return 'Что-то пошло не так, попробуй ещё раз чуть позже.';
	}
}
