(function () {
	'use strict';

	// Player card of the owner: player.html?ctx=… (button) or opened by the Direct Mini App link
	// (app.html dispatches here; the card id is in the signed start_param, no ctx). API ops player_*.
	var tg = window.Telegram && window.Telegram.WebApp;
	var ctx = new URLSearchParams(window.location.search).get('ctx') || '';
	var API_URL = '../webapp_api.php';

	var boot = null;
	var initial = null;
	var level = null;
	var busy = false;
	var closed = false;
	var results = [];
	var target = null;
	var search_timer = 0;
	var search_seq = 0;
	var sheet_action = null;

	function $(id) {
		return document.getElementById(id);
	}

	var el = {
		form: $('form'),
		fields: $('fields'),
		loading: $('loading'),
		banner: $('error'),
		heading: $('heading'),
		subtitle: $('subtitle'),
		ign: $('ign'),
		ign_hint: $('ign-hint'),
		ign_error: $('ign-error'),
		levels: $('levels'),
		level_hint: $('level-hint'),
		level_error: $('level-error'),
		username: $('username'),
		tg_dot: $('tg-dot'),
		tg_text: $('tg-text'),
		username_hint: $('username-hint'),
		username_error: $('username-error'),
		unbind_row: $('unbind-row'),
		unbind: $('unbind'),
		unbind_error: $('unbind-error'),
		team: $('team'),
		team_error: $('team-error'),
		search: $('search'),
		results: $('results'),
		chosen: $('merge-chosen'),
		chosen_name: $('chosen-name'),
		chosen_clear: $('chosen-clear'),
		merge_go: $('merge-go'),
		sheet: $('sheet'),
		sheet_title: $('sheet-title'),
		sheet_text: $('sheet-text'),
		sheet_list: $('sheet-list'),
		sheet_ok: $('sheet-ok'),
		sheet_cancel: $('sheet-cancel'),
		del: $('delete'),
		submit: $('submit')
	};

	var FIELD_ERRORS = {
		ign: el.ign_error,
		level: el.level_error,
		username: el.username_error,
		confirm_unbind: el.unbind_error,
		preferred_team_name: el.team_error
	};

	function node(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) {
			n.className = cls;
		}
		if (text !== undefined && text !== null) {
			n.textContent = text;
		}
		return n;
	}

	function setError(target_el, text) {
		target_el.textContent = text || '';
		target_el.hidden = !text;
	}

	function clearErrors() {
		setError(el.banner, '');
		Object.keys(FIELD_ERRORS).forEach(function (key) {
			setError(FIELD_ERRORS[key], '');
		});
		[el.ign, el.username, el.team].forEach(function (input) {
			input.classList.remove('bad');
		});
	}

	function showErrors(data, message) {
		var fields = (data && data.fields) || {};
		var shown = false;
		Object.keys(fields).forEach(function (key) {
			if (FIELD_ERRORS[key]) {
				setError(FIELD_ERRORS[key], fields[key]);
				shown = true;
			}
		});
		if (fields.ign) {
			el.ign.classList.add('bad');
		}
		if (fields.username || fields.confirm_unbind) {
			el.username.classList.add('bad');
		}
		if (fields.preferred_team_name) {
			el.team.classList.add('bad');
		}
		if (!shown) {
			setError(el.banner, message);
			window.scrollTo(0, 0);
		}
	}

	function normUsername(value) {
		return String(value || '').trim().replace(/^@+/, '').toLowerCase();
	}

	function normSpaces(value) {
		return String(value || '').replace(/\s+/g, ' ').trim();
	}

	function levelText(value, name) {
		return value === null ? 'не ставили' : name + ' (' + value + ')';
	}

	function usernameChanged() {
		return normUsername(el.username.value) !== initial.username;
	}

	// Re-pointing the @username of a bound card detaches the Telegram account: ask first.
	function needsUnbind() {
		return boot.player.tg_bound && usernameChanged() && normUsername(el.username.value) !== '';
	}

	function dirty() {
		return normSpaces(el.ign.value) !== initial.ign
			|| level !== initial.level
			|| usernameChanged()
			|| normSpaces(el.team.value) !== initial.team;
	}

	function render() {
		var need = needsUnbind();
		el.unbind_row.hidden = !need;
		if (!need) {
			el.unbind.checked = false;
		}
		el.submit.disabled = busy || closed || !dirty() || (need && !el.unbind.checked);
		el.del.disabled = busy || closed;
		el.merge_go.disabled = busy || closed;
		var current = level === null
			? 'Уровень не ставили: в командах считаю ' + boot.default_level_name + ' (' + boot.default_level + ').'
			: 'Сейчас: ' + levelText(level, boot.levels.filter(function (l) {
				return l.level === level;
			})[0].name) + '.';
		el.level_hint.textContent = current + ' «нет» — снять уровень.';
		Array.prototype.forEach.call(el.levels.children, function (button) {
			var value = button.getAttribute('data-level');
			var on = value === '' ? level === null : level === Number(value);
			button.classList.toggle('on', on);
			button.setAttribute('aria-checked', on ? 'true' : 'false');
		});
	}

	function renderLevels() {
		el.levels.textContent = '';
		boot.levels.forEach(function (item) {
			var button = node('button', '', String(item.level));
			button.type = 'button';
			button.setAttribute('role', 'radio');
			button.setAttribute('data-level', String(item.level));
			button.title = item.name;
			el.levels.appendChild(button);
		});
		var none = node('button', 'none', 'нет');
		none.type = 'button';
		none.setAttribute('role', 'radio');
		none.setAttribute('data-level', '');
		el.levels.appendChild(none);
	}

	function tgStatus(player) {
		if (!player.tg_bound) {
			return ['off', 'Telegram не привязан: у карточки нет Telegram id. Привяжется сам, когда человек с этим @username напишет мне.'];
		}
		if (player.dm_ok) {
			return ['', 'Привязан, id ' + player.tg_id + '. Писал мне в личку — сообщения дойдут.'];
		}
		return ['off', 'Привязан, id ' + player.tg_id + '. Мне в личку ещё не писали — сообщения не дойдут.'];
	}

	function usernameHint(player) {
		if (player.is_owner) {
			return 'Это главный админ: @username подтягивается из Telegram, его не меняю.';
		}
		if (player.tg_bound) {
			return 'Другой @username отвяжет текущий Telegram-аккаунт от карточки. Telegram id сама не меняю.';
		}
		return 'Можно указать или поменять @username: карточку займёт тот, кто напишет мне с ним.';
	}

	function start(data) {
		boot = data;
		var p = data.player;
		initial = {
			ign: p.ign || '',
			username: p.username || '',
			level: p.level,
			team: p.preferred_team_name || ''
		};
		level = p.level;
		el.heading.textContent = p.title;
		el.subtitle.textContent = 'Карточка игрока · уровень ' + levelText(p.level, p.level_name);
		el.ign.value = initial.ign;
		el.ign.maxLength = data.limits.ign_max;
		el.ign_hint.textContent = 'Уникальный: от ' + data.limits.ign_min + ' до ' + data.limits.ign_max
			+ ' символов. Если очистить, в списке останется @username.';
		el.username.value = initial.username ? '@' + initial.username : '';
		el.username.readOnly = p.is_owner;
		el.username_hint.textContent = usernameHint(p);
		var status = tgStatus(p);
		el.tg_dot.className = 'dot' + (status[0] ? ' ' + status[0] : '');
		el.tg_text.textContent = status[1];
		el.team.value = initial.team;
		el.team.maxLength = data.limits.team_name_max;
		el.del.hidden = false;
		el.submit.hidden = false;
		renderLevels();
		el.loading.hidden = true;
		el.fields.hidden = false;
		el.fields.disabled = false;
		render();
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

	function lockForm(message) {
		closed = true;
		el.fields.disabled = true;
		el.submit.disabled = true;
		el.del.disabled = true;
		if (message) {
			setError(el.banner, message);
		}
	}

	function act(op, extra) {
		if (busy || closed) {
			return;
		}
		busy = true;
		clearErrors();
		render();
		api(op, extra).then(function () {
			busy = false;
			lockForm('');
			if (tg) {
				tg.close();
			}
		}, function (err) {
			busy = false;
			var reason = err.data && err.data.reason;
			if (reason === 'expired' || reason === 'forbidden') {
				lockForm(err.message);
				return;
			}
			showErrors(err.data, err.message);
			render();
		});
	}

	function formData() {
		return {
			ign: normSpaces(el.ign.value),
			level: level,
			username: normUsername(el.username.value),
			preferred_team_name: normSpaces(el.team.value),
			confirm_unbind: el.unbind.checked
		};
	}

	function openSheet(opts) {
		el.sheet_title.textContent = opts.title;
		el.sheet_text.textContent = opts.text;
		el.sheet_list.textContent = '';
		(opts.items || []).forEach(function (item) {
			el.sheet_list.appendChild(node('li', '', item));
		});
		el.sheet_list.hidden = !(opts.items && opts.items.length);
		el.sheet_ok.textContent = opts.ok;
		el.sheet_cancel.hidden = !!opts.only_ok;
		sheet_action = opts.action || null;
		el.sheet.hidden = false;
	}

	function closeSheet() {
		el.sheet.hidden = true;
		sheet_action = null;
	}

	function setTarget(candidate) {
		target = candidate;
		el.chosen.hidden = !candidate;
		el.merge_go.hidden = !candidate;
		el.merge_go.textContent = candidate ? '🔗 Объединить с «' + candidate.title + '»' : '🔗 Объединить';
		el.chosen_name.textContent = candidate ? candidate.title : '';
		el.results.hidden = true;
		el.search.value = '';
	}

	function renderResults() {
		el.results.textContent = '';
		if (!results.length) {
			el.results.appendChild(node('div', 'none', 'Никого не нашла.'));
			el.results.hidden = false;
			return;
		}
		results.forEach(function (candidate) {
			var row = node('button', 'r');
			row.type = 'button';
			row.setAttribute('role', 'option');
			row.disabled = !!candidate.problem;
			var main = node('span', 'main');
			main.appendChild(node('span', 'n', candidate.title));
			if (candidate.problem) {
				main.appendChild(node('span', 'why', candidate.problem));
			}
			row.appendChild(main);
			row.appendChild(node('span', 'lv', 'ур. ' + candidate.level + (candidate.level_explicit ? '' : '*')));
			row.addEventListener('click', function () {
				setTarget(candidate);
			});
			el.results.appendChild(row);
		});
		el.results.hidden = false;
	}

	function runSearch() {
		var seq = ++search_seq;
		api('player_search', { q: el.search.value.trim() }).then(function (data) {
			if (seq !== search_seq) {
				return;
			}
			results = data.players || [];
			renderResults();
		}, function () {
			if (seq === search_seq) {
				results = [];
				renderResults();
			}
		});
	}

	el.levels.addEventListener('click', function (e) {
		var button = e.target.closest('button');
		if (!button || closed) {
			return;
		}
		var value = button.getAttribute('data-level');
		level = value === '' ? null : Number(value);
		setError(el.level_error, '');
		render();
	});
	[el.ign, el.username, el.team].forEach(function (input) {
		input.addEventListener('input', function () {
			input.classList.remove('bad');
			setError(FIELD_ERRORS[input === el.ign ? 'ign' : input === el.username ? 'username' : 'preferred_team_name'], '');
			render();
		});
	});
	el.unbind.addEventListener('change', function () {
		setError(el.unbind_error, '');
		render();
	});
	el.search.addEventListener('input', function () {
		clearTimeout(search_timer);
		search_timer = setTimeout(runSearch, 250);
	});
	el.search.addEventListener('focus', function () {
		if (!results.length) {
			runSearch();
		}
	});
	el.chosen_clear.addEventListener('click', function () {
		setTarget(null);
		el.search.focus();
	});
	el.merge_go.addEventListener('click', function () {
		if (!target) {
			return;
		}
		var into = target;
		openSheet({
			title: 'Объединить с «' + into.title + '»?',
			text: 'Карточка «' + boot.player.title + '» вольётся в «' + into.title + '»: места в командах, уровень, ник и история перейдут на неё, а эта карточка исчезнет. Если у «' + into.title + '» уже есть ник, @username или уровень, останутся её. Это нельзя отменить.',
			ok: 'Объединить',
			action: function () {
				act('player_merge', { into_id: into.id });
			}
		});
	});
	el.del.addEventListener('click', function () {
		var blockers = boot.delete_blockers || [];
		if (blockers.length) {
			openSheet({
				title: 'Удалить нельзя',
				text: 'Пока игрок ещё играет:',
				items: blockers,
				ok: 'Понятно',
				only_ok: true
			});
			return;
		}
		openSheet({
			title: 'Удалить «' + boot.player.title + '»?',
			text: 'В активных командах игрока нет. В истории турниров он останется как «удалённый игрок», чтобы составы прошлого не разъехались. Ник, @username и Telegram освободятся. Это нельзя отменить.',
			ok: 'Удалить игрока',
			action: function () {
				act('player_delete', {});
			}
		});
	});
	el.sheet_ok.addEventListener('click', function () {
		var action = sheet_action;
		closeSheet();
		if (action) {
			action();
		}
	});
	el.sheet_cancel.addEventListener('click', closeSheet);
	el.sheet.addEventListener('click', function (e) {
		if (e.target === el.sheet) {
			closeSheet();
		}
	});
	el.form.addEventListener('submit', function (e) {
		e.preventDefault();
		if (el.submit.disabled) {
			return;
		}
		act('player_save', { form: formData() });
	});

	if (!tg || !tg.initData) {
		el.loading.hidden = true;
		setError(el.banner, 'Открой карточку ✏️ из списка /players в Telegram.');
		return;
	}
	tg.ready();
	tg.expand();
	api('player_bootstrap').then(start, function (err) {
		el.loading.hidden = true;
		lockForm(err.message);
	});
}());
