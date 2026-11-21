(function () {
	'use strict';

	var tg = window.Telegram && window.Telegram.WebApp;
	var params = new URLSearchParams(window.location.search);
	var ctx = params.get('ctx') || '';
	// The same page edits a draft: create.html?ctx=…&mode=edit (API ops edit_*).
	var form_mode = params.get('mode') === 'edit' ? 'edit' : 'create';
	var SUBMIT_TEXT = form_mode === 'edit' ? 'Сохранить' : 'Создать черновик';
	var BUSY_TEXT = form_mode === 'edit' ? 'Сохраняю…' : 'Создаю…';
	var MONTHS = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь',
		'Октябрь', 'Ноябрь', 'Декабрь'];
	var WEEKDAYS = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];
	var MINUTE_STEP = 5;
	var API_URL = '../webapp_api.php';

	var boot = null;
	var clock_offset = 0;
	var mode = 'players';
	var levels = [];
	var touched = {};
	var points_touched = false;
	var server_errors = {};
	var submitted = false;
	var busy = false;
	var closed = false;
	var view = { year: 0, month: 0 };

	function $(id) {
		return document.getElementById(id);
	}

	var el = {
		form: $('form'),
		fields: $('fields'),
		loading: $('loading'),
		banner: $('error'),
		title: $('title'),
		date: $('date'),
		time: $('time'),
		heading: $('heading'),
		date_button: $('date-button'),
		hour: $('hour'),
		minute: $('minute'),
		time_picker: document.querySelector('.time-picker'),
		calendar: $('calendar'),
		days: $('days'),
		month_title: $('month-title'),
		month_prev: $('month-prev'),
		month_next: $('month-next'),
		close_hint: $('close-hint'),
		lock_hint: $('lock-hint'),
		level_lock: $('level-lock'),
		team_size: $('team-size'),
		help: $('help'),
		help_toggle: $('help-toggle'),
		help_levels: $('help-levels'),
		mode_players: $('mode-players'),
		mode_sum: $('mode-sum'),
		players_mode: $('players-mode'),
		sum_mode: $('sum-mode'),
		slots: $('slots'),
		total: $('total'),
		points: $('points'),
		slider: $('slider'),
		points_min: $('points-min'),
		points_max: $('points-max'),
		sum_hint: $('sum-hint'),
		max_players: $('max-players'),
		teams_hint: $('teams-hint'),
		submit: $('submit')
	};

	// Russian plural: forms = [1 игрок, 2 игрока, 5 игроков].
	function plural(n, forms) {
		var a = Math.abs(n) % 100;
		var b = a % 10;
		if (a >= 11 && a <= 14) {
			return forms[2];
		}
		if (b === 1) {
			return forms[0];
		}
		if (b >= 2 && b <= 4) {
			return forms[1];
		}
		return forms[2];
	}

	function pad(n) {
		return (n < 10 ? '0' : '') + n;
	}

	function levelName(level) {
		for (var i = 0; i < boot.levels.length; i++) {
			if (boot.levels[i].level === level) {
				return boot.levels[i].name;
			}
		}
		return String(level);
	}

	// «средний» → «средним», «высокий+» → «высоким+» (level names are adjectives).
	function instrumental(name) {
		return name.replace(/(ий|ый)(\+?)$/, function (m, ending, plus) {
			return (ending === 'ий' ? 'им' : 'ым') + plus;
		});
	}

	function teamSize() {
		return parseInt(el.team_size.value, 10) || boot.team_size['default'];
	}

	function intOf(input) {
		var v = String(input.value).trim();
		return /^\d{1,6}$/.test(v) ? parseInt(v, 10) : null;
	}

	// Moscow wall-clock time as a UTC timestamp: no device timezone is involved.
	function parseMsk(date, time) {
		var d = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date || '');
		var t = /^(\d{2}):(\d{2})/.exec(time || '');
		if (!d || !t) {
			return null;
		}
		var ms = Date.UTC(+d[1], +d[2] - 1, +d[3], +t[1], +t[2]);
		var check = new Date(ms);
		if (check.getUTCDate() !== +d[3] || +t[1] > 23 || +t[2] > 59) {
			return null;
		}
		return ms;
	}

	function mskNow() {
		return Date.now() + clock_offset;
	}

	// Today in Moscow as Y-m-d.
	function todayMsk() {
		var d = new Date(mskNow());
		return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate());
	}

	function ymd(year, month, day) {
		return year + '-' + pad(month + 1) + '-' + pad(day);
	}

	// «Сб, 21.11.2026», independent of the device locale.
	function displayDate(value) {
		var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '');
		if (!m) {
			return 'Выбери дату';
		}
		var d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]));
		return WEEKDAYS[d.getUTCDay()] + ', ' + m[3] + '.' + m[2] + '.' + m[1];
	}

	// Month grid, Monday first; days before today (MSK) are disabled.
	function renderCalendar() {
		var today = todayMsk();
		el.month_title.textContent = MONTHS[view.month] + ' ' + view.year;
		el.month_prev.disabled = ymd(view.year, view.month, 1) <= today.slice(0, 8) + '01';
		el.days.innerHTML = '';
		var first = new Date(Date.UTC(view.year, view.month, 1));
		var blanks = (first.getUTCDay() + 6) % 7;
		var count = new Date(Date.UTC(view.year, view.month + 1, 0)).getUTCDate();
		for (var i = 0; i < blanks; i++) {
			el.days.appendChild(document.createElement('span'));
		}
		for (var day = 1; day <= count; day++) {
			var value = ymd(view.year, view.month, day);
			var b = document.createElement('button');
			b.type = 'button';
			b.textContent = day;
			b.setAttribute('data-date', value);
			b.setAttribute('aria-label', displayDate(value));
			b.disabled = value < today;
			b.classList.toggle('today', value === today);
			b.classList.toggle('selected', value === el.date.value);
			if (value === el.date.value) {
				b.setAttribute('aria-pressed', 'true');
			}
			el.days.appendChild(b);
		}
	}

	function showMonth(value) {
		var m = /^(\d{4})-(\d{2})/.exec(value || todayMsk());
		view.year = +m[1];
		view.month = +m[2] - 1;
		renderCalendar();
	}

	function toggleCalendar(open) {
		el.calendar.hidden = !open;
		el.date_button.setAttribute('aria-expanded', open ? 'true' : 'false');
		if (open) {
			showMonth(el.date.value);
		}
	}

	function setDate(value) {
		el.date.value = value;
		el.date_button.textContent = displayDate(value);
	}

	function fillSelect(select, from, to, step) {
		for (var v = from; v <= to; v += step) {
			var o = document.createElement('option');
			o.value = pad(v);
			o.textContent = pad(v);
			select.appendChild(o);
		}
	}

	// 24-hour time from two selects; an off-step minute of a draft is kept as an extra option.
	function setTime(value) {
		var m = /^(\d{2}):(\d{2})/.exec(value || '') || [null, '20', '00'];
		if (!el.minute.querySelector('option[value="' + m[2] + '"]')) {
			var o = document.createElement('option');
			o.value = m[2];
			o.textContent = m[2];
			var next = Array.prototype.find.call(el.minute.options, function (opt) { return opt.value > m[2]; });
			el.minute.insertBefore(o, next || null);
		}
		el.hour.value = m[1];
		el.minute.value = m[2];
		el.time.value = m[1] + ':' + m[2];
	}

	function pointsRange() {
		var n = teamSize();
		return { min: n * boot.level_min, max: n * boot.level_max };
	}

	function validate() {
		var errors = {};
		var title = el.title.value.trim();
		if (title === '') {
			errors.title = 'Введи название турнира';
		} else if (title.length > boot.title_max) {
			errors.title = 'Название — не длиннее ' + boot.title_max + ' символов';
		}
		if (!el.date.value) {
			errors.start = 'Выбери дату начала';
		} else if (!el.time.value) {
			errors.start = 'Выбери время начала';
		} else {
			var start = parseMsk(el.date.value, el.time.value);
			if (start === null) {
				errors.start = 'Такой даты нет';
			} else if (start <= mskNow() && !keepsPublishedStart()) {
				errors.start = 'Это время уже прошло. Выбери будущее время по МСК';
			}
		}
		var n = teamSize();
		if (mode === 'sum') {
			var p = intOf(el.points);
			var r = pointsRange();
			if (p === null || p < r.min || p > r.max) {
				errors.level = 'Сумма баллов для этой команды — от ' + r.min + ' до ' + r.max;
			}
		} else if (levels.length !== n) {
			errors.level = 'Укажи уровень каждого игрока';
		}
		var m = intOf(el.max_players);
		if (m === null || m < n || m > boot.max_players.max) {
			errors.max_players = 'Лимит участников — от ' + n + ' до ' + boot.max_players.max;
		}
		return errors;
	}

	function showFieldError(field, input, message) {
		var box = $(field + '-error');
		box.hidden = !message;
		box.textContent = message || '';
		if (input) {
			input.classList.toggle('bad', !!message);
			input.setAttribute('aria-invalid', message ? 'true' : 'false');
		}
	}

	function render() {
		if (!boot) {
			return;
		}
		var n = teamSize();
		var errors = validate();
		var shown = {};
		['title', 'start', 'team_size', 'level', 'max_players'].forEach(function (f) {
			var msg = server_errors[f] || ((submitted || touched[f]) ? errors[f] : '');
			shown[f] = msg || '';
		});
		showFieldError('title', el.title, shown.title);
		showFieldError('start', null, shown.start);
		el.date_button.classList.toggle('bad', !!shown.start);
		el.time_picker.classList.toggle('bad', !!shown.start);
		showFieldError('team_size', el.team_size, shown.team_size);
		showFieldError('level', mode === 'sum' ? el.points : null, shown.level);
		showFieldError('max_players', el.max_players, shown.max_players);

		// Registration closes REGISTRATION_CLOSE_MINUTES before the start.
		var start = parseMsk(el.date.value, el.time.value);
		if (start !== null && boot.registration_close_minutes > 0) {
			var close = new Date(start - boot.registration_close_minutes * 60000);
			var same_day = close.getUTCDate() === new Date(start).getUTCDate();
			el.close_hint.textContent = 'Регистрация закроется '
				+ (same_day ? '' : pad(close.getUTCDate()) + '.' + pad(close.getUTCMonth() + 1) + ' ')
				+ 'в ' + pad(close.getUTCHours()) + ':' + pad(close.getUTCMinutes());
			el.close_hint.hidden = false;
		} else {
			el.close_hint.hidden = true;
		}

		var sum = levels.reduce(function (a, b) { return a + b; }, 0);
		el.total.textContent = sum + ' ' + plural(sum, ['балл', 'балла', 'баллов']);

		var r = pointsRange();
		el.points.min = el.slider.min = r.min;
		el.points.max = el.slider.max = r.max;
		el.points_min.textContent = r.min;
		el.points_max.textContent = r.max;
		var p = intOf(el.points);
		if (p !== null) {
			el.slider.value = Math.max(r.min, Math.min(r.max, p));
		}
		el.sum_hint.textContent = n === 1
			? 'Уровень игрока не больше ' + (p === null ? '…' : p)
			: 'Сумма уровней всех игроков команды не больше ' + (p === null ? '…' : p);

		var m = intOf(el.max_players);
		if (m !== null && m >= n) {
			var teams = Math.floor(m / n);
			el.teams_hint.textContent = teams + ' ' + plural(teams, ['команда', 'команды', 'команд'])
				+ ' по ' + n + ' ' + plural(n, ['игроку', 'игрока', 'игроков']);
			el.teams_hint.hidden = false;
		} else {
			el.teams_hint.hidden = true;
		}

		document.querySelectorAll('[data-step]').forEach(function (b) {
			var parts = b.getAttribute('data-step').split(':');
			var input = $(parts[0]);
			var v = intOf(input);
			var delta = parseInt(parts[1], 10);
			b.disabled = v !== null && ((delta < 0 && v <= +input.min) || (delta > 0 && v >= +input.max));
		});

		var ok = Object.keys(errors).length === 0;
		el.submit.disabled = closed ? !tg : (busy || !ok);
	}

	function renderSlots() {
		el.slots.innerHTML = '';
		levels.forEach(function (value, i) {
			var row = document.createElement('div');
			row.className = 'slot';
			var label = document.createElement('label');
			label.htmlFor = 'slot-' + i;
			label.textContent = 'Игрок ' + (i + 1);
			var select = document.createElement('select');
			select.id = 'slot-' + i;
			boot.levels.forEach(function (opt) {
				var o = document.createElement('option');
				o.value = opt.level;
				o.textContent = opt.name + ' (' + opt.level + ')';
				select.appendChild(o);
			});
			select.value = String(value);
			select.addEventListener('change', function () {
				levels[i] = parseInt(select.value, 10);
				touched.level = true;
				delete server_errors.level;
				render();
			});
			row.appendChild(label);
			row.appendChild(select);
			el.slots.appendChild(row);
		});
	}

	// New team size: keep chosen slot levels, fill new slots with the default, re-fit ranges.
	function fitTeamSize() {
		var n = teamSize();
		while (levels.length < n) {
			levels.push(boot.default_level);
		}
		levels.length = n;
		var r = pointsRange();
		var p = intOf(el.points);
		if (!points_touched || p === null) {
			el.points.value = n * boot.default_level;
		} else {
			el.points.value = Math.max(r.min, Math.min(r.max, p));
		}
		var m = intOf(el.max_players);
		el.max_players.min = n;
		if (m !== null && m < n) {
			el.max_players.value = n;
		}
		renderSlots();
	}

	function setMode(next) {
		mode = next;
		el.mode_players.classList.toggle('selected', mode === 'players');
		el.mode_sum.classList.toggle('selected', mode === 'sum');
		el.mode_players.setAttribute('aria-pressed', mode === 'players' ? 'true' : 'false');
		el.mode_sum.setAttribute('aria-pressed', mode === 'sum' ? 'true' : 'false');
		el.players_mode.hidden = mode !== 'players';
		el.sum_mode.hidden = mode !== 'sum';
		delete server_errors.level;
		render();
	}

	function showBanner(message) {
		el.banner.hidden = !message;
		el.banner.textContent = message || '';
	}

	// The form context expired or was never valid: ask to reopen, the button just closes.
	function lockForm(message) {
		showBanner(message);
		el.fields.disabled = true;
		el.loading.hidden = true;
		closed = true;
		el.submit.textContent = 'Закрыть';
		el.submit.disabled = !tg;
	}

	function api(op, extra) {
		var body = Object.assign({ op: op, ctx: ctx, init_data: tg ? tg.initData : '' }, extra || {});
		return fetch(API_URL, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(body)
		}).then(function (res) {
			return res.json().catch(function () {
				return { ok: false, error: 'Не получилось связаться с ботом. Попробуй ещё раз.' };
			}).then(function (data) {
				if (!res.ok || !data || data.ok === false) {
					var err = new Error((data && data.error) || 'Ошибка запроса');
					err.data = data || {};
					throw err;
				}
				return data;
			});
		}, function () {
			var err = new Error('Нет связи. Проверь интернет и попробуй ещё раз.');
			err.data = {};
			throw err;
		});
	}

	function formData() {
		var data = {
			title: el.title.value.trim(),
			date: el.date.value,
			time: el.time.value,
			team_size: teamSize(),
			level_mode: mode,
			max_players: intOf(el.max_players)
		};
		if (mode === 'sum') {
			data.points = intOf(el.points);
		} else {
			data.levels = levels.slice();
		}
		return data;
	}

	function submit(e) {
		e.preventDefault();
		if (closed) {
			if (tg) {
				tg.close();
			}
			return;
		}
		submitted = true;
		server_errors = {};
		showBanner('');
		render();
		if (busy || Object.keys(validate()).length > 0) {
			return;
		}
		busy = true;
		el.submit.textContent = BUSY_TEXT;
		render();
		api(form_mode + '_submit', { form: formData() }).then(function () {
			if (tg && tg.HapticFeedback) {
				tg.HapticFeedback.notificationOccurred('success');
			}
			if (tg) {
				tg.close();
			}
		}).catch(function (err) {
			busy = false;
			el.submit.textContent = SUBMIT_TEXT;
			var data = err.data || {};
			if (data.reason === 'expired' || data.reason === 'forbidden' || data.reason === 'not_draft') {
				lockForm(err.message);
				return;
			}
			server_errors = data.fields || {};
			if (!data.field) {
				showBanner(err.message);
			}
			render();
		});
	}

	// A published tournament may keep its start even after it passed; only a new time must be future.
	function keepsPublishedStart() {
		var t = boot && boot.tournament;
		return !!(boot && boot.published && t && el.date.value === t.date && el.time.value === t.time);
	}

	function start(data) {
		boot = data;
		clock_offset = Date.parse(data.now_msk.replace(' ', 'T') + 'Z') - Date.now();
		el.help_levels.textContent = 'У каждого игрока уровень от ' + data.level_min + ' до ' + data.level_max + ': '
			+ data.levels.map(function (l) { return l.level + ' ' + l.name; }).join(', ')
			+ '. Игрок без уровня считается ' + instrumental(levelName(data.default_level))
			+ ' (' + data.default_level + ').';
		for (var n = data.team_size.min; n <= data.team_size.max; n++) {
			var o = document.createElement('option');
			o.value = n;
			o.textContent = n + ' ' + plural(n, ['игрок', 'игрока', 'игроков']) + (n === 1 ? ' (соло)' : '');
			el.team_size.appendChild(o);
		}
		el.team_size.value = String(data.team_size['default']);
		el.title.maxLength = data.title_max;
		fillSelect(el.hour, 0, 23, 1);
		fillSelect(el.minute, 0, 59, MINUTE_STEP);
		el.max_players.max = data.max_players.max;
		var t = data.tournament;
		if (form_mode === 'edit' && t) {
			el.title.value = t.title;
			setDate(t.date);
			setTime(t.time);
			el.team_size.value = String(t.team_size);
			el.max_players.value = t.max_players;
			levels = t.levels.slice();
			el.points.value = t.points;
			points_touched = true;
			// A draft may already be in the past: show why it cannot be saved as is.
			touched.start = true;
		} else {
			setDate(data.default_date);
			setTime(data.default_time);
			el.max_players.value = data.max_players['default'];
		}
		fitTeamSize();
		if (form_mode === 'edit' && t) {
			setMode(t.level_mode);
		}
		if (data.published) {
			el.heading.textContent = 'Изменить турнир';
			document.title = 'Изменить турнир';
		}
		// Registrations exist: team size stays as it is; the level may still be changed.
		el.team_size.disabled = !!data.size_locked;
		el.lock_hint.hidden = !data.size_locked;
		el.lock_hint.textContent = data.size_locked_text || '';
		el.loading.hidden = true;
		el.fields.disabled = false;
		render();
	}

	function onInput(field, input) {
		input.addEventListener('input', function () {
			delete server_errors[field];
			render();
		});
		input.addEventListener('blur', function () {
			touched[field] = true;
			render();
		});
	}

	onInput('title', el.title);
	el.date_button.addEventListener('click', function () {
		toggleCalendar(el.calendar.hidden);
	});
	el.month_prev.addEventListener('click', function () {
		view.month -= 1;
		if (view.month < 0) {
			view.month = 11;
			view.year -= 1;
		}
		renderCalendar();
	});
	el.month_next.addEventListener('click', function () {
		view.month += 1;
		if (view.month > 11) {
			view.month = 0;
			view.year += 1;
		}
		renderCalendar();
	});
	el.days.addEventListener('click', function (e) {
		var b = e.target.closest('button[data-date]');
		if (!b || b.disabled) {
			return;
		}
		setDate(b.getAttribute('data-date'));
		touched.start = true;
		delete server_errors.start;
		toggleCalendar(false);
		el.date_button.focus();
		render();
	});
	[el.hour, el.minute].forEach(function (select) {
		select.addEventListener('change', function () {
			el.time.value = el.hour.value + ':' + el.minute.value;
			touched.start = true;
			delete server_errors.start;
			render();
		});
	});
	onInput('max_players', el.max_players);
	onInput('level', el.points);
	el.points.addEventListener('input', function () {
		points_touched = true;
	});
	el.slider.addEventListener('input', function () {
		el.points.value = el.slider.value;
		points_touched = true;
		delete server_errors.level;
		render();
	});
	el.team_size.addEventListener('change', function () {
		delete server_errors.team_size;
		fitTeamSize();
		render();
	});
	el.mode_players.addEventListener('click', function () { setMode('players'); });
	el.mode_sum.addEventListener('click', function () { setMode('sum'); });
	el.help_toggle.addEventListener('click', function () {
		el.help.hidden = !el.help.hidden;
		el.help_toggle.setAttribute('aria-expanded', el.help.hidden ? 'false' : 'true');
	});
	$('help-close').addEventListener('click', function () {
		el.help.hidden = true;
		el.help_toggle.setAttribute('aria-expanded', 'false');
		el.help_toggle.focus();
	});
	document.querySelectorAll('[data-step]').forEach(function (b) {
		b.addEventListener('click', function () {
			var parts = b.getAttribute('data-step').split(':');
			var input = $(parts[0]);
			var v = intOf(input);
			var min = +input.min;
			var max = +input.max;
			v = v === null ? min : v + parseInt(parts[1], 10);
			input.value = Math.max(min, Math.min(max, v));
			if (input === el.points) {
				points_touched = true;
				delete server_errors.level;
			} else {
				delete server_errors.max_players;
			}
			render();
		});
	});
	el.form.addEventListener('submit', submit);

	if (form_mode === 'edit') {
		el.heading.textContent = 'Правка турнира';
		document.title = 'Правка турнира';
	}
	el.submit.textContent = SUBMIT_TEXT;
	if (!tg || !tg.initData) {
		lockForm(form_mode === 'edit'
			? 'Открой форму кнопкой «✏️ Поправить» или «✏️ Изменить турнир» на карточке в личке с ботом.'
			: 'Открой форму из лички с ботом в Telegram: /create_tournament.');
		return;
	}
	tg.ready();
	tg.expand();
	api(form_mode + '_bootstrap').then(start).catch(function (err) {
		lockForm(err.message);
	});
})();
