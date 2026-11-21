(function () {
	'use strict';

	// Team form: team.html?ctx=… (API ops team_*). The server decides the mode and the role.
	var tg = window.Telegram && window.Telegram.WebApp;
	var ctx = new URLSearchParams(window.location.search).get('ctx') || '';
	var opening_id = (window.crypto && crypto.randomUUID)
		? crypto.randomUUID().replace(/-/g, '')
		: String(Date.now()) + '-' + Math.random().toString(36).slice(2);
	var API_URL = '../webapp_api.php';
	var COLORS = ['#e67e22', '#8e44ad', '#16a085', '#2980b9', '#c0392b', '#27ae60', '#d35400', '#7f8c8d'];

	var boot = null;
	var members = [];
	var captain_id = 0;
	var letter = null;
	var letter_touched = false;
	var pending = [];
	var results = [];
	var menu_id = 0;
	var busy = false;
	var closed = false;
	var search_timer = 0;
	var search_seq = 0;

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
		letter_label: $('letter-label'),
		letters: $('letters'),
		letter_hint: $('letter-hint'),
		letter_error: $('letter-error'),
		name: $('name'),
		name_hint: $('name-hint'),
		name_error: $('name-error'),
		roster_label: $('roster-label'),
		roster: $('roster'),
		pending: $('pending'),
		search_box: $('search-box'),
		search: $('search'),
		results: $('results'),
		contacts: $('contacts'),
		sum: $('sum'),
		bar: $('bar'),
		bar_fill: $('bar-fill'),
		roster_error: $('roster-error'),
		roster_hint: $('roster-hint'),
		readonly_hint: $('readonly-hint'),
		menu: $('menu'),
		menu_title: $('menu-title'),
		menu_captain: $('menu-captain'),
		menu_remove: $('menu-remove'),
		menu_close: $('menu-close'),
		disband: $('disband'),
		leave: $('leave'),
		submit: $('submit')
	};

	// Russian plural: forms = [1 балл, 2 балла, 5 баллов].
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

	function readonly() {
		return !boot || boot.readonly;
	}

	function teamSize() {
		return boot.tournament.team_size;
	}

	function points() {
		var sum = 0;
		members.forEach(function (m) {
			sum += m.level;
		});
		return sum;
	}

	function overLimit() {
		var max = boot.tournament.max_points;
		return max !== null && points() > max;
	}

	// An existing over-limit team (after the tournament level was lowered) may be saved
	// if the sum does not go above what is already saved.
	function worseThanSaved() {
		if (!overLimit()) {
			return false;
		}
		var saved = boot.team && boot.team.saved_points;
		return !(boot.mode === 'edit' && saved != null && points() <= saved);
	}

	function hasMember(id) {
		return members.some(function (m) {
			return m.id === id;
		});
	}

	function avatar(card) {
		var a = node('div', 'av', (card.name || '?').charAt(0).toUpperCase());
		a.style.background = COLORS[card.id % COLORS.length];
		a.setAttribute('aria-hidden', 'true');
		return a;
	}

	function subLine(card, extra) {
		var bits = [];
		if (card.in_team) {
			return 'уже в команде «' + card.in_team + '»';
		}
		if (card.username) {
			bits.push('@' + card.username);
		}
		if (extra && card.display_name && card.display_name !== card.name) {
			bits.push(card.display_name);
		}
		return bits.join(' · ');
	}

	function levelText(card) {
		return card.level_name + ' (' + card.level + ')';
	}

	function setError(target, message) {
		target.textContent = message || '';
		target.hidden = !message;
	}

	function clearErrors() {
		setError(el.banner, '');
		setError(el.name_error, '');
		setError(el.roster_error, '');
		setError(el.letter_error, '');
		el.name.classList.remove('bad');
	}

	function showErrors(data, fallback) {
		var fields = (data && data.fields) || {};
		var shown = false;
		if (fields.name) {
			setError(el.name_error, fields.name);
			el.name.classList.add('bad');
			shown = true;
		}
		if (fields.roster) {
			setError(el.roster_error, fields.roster);
			shown = true;
		}
		if (fields.letter) {
			setError(el.letter_error, fields.letter);
			shown = true;
		}
		if (!shown) {
			setError(el.banner, (data && data.error) || fallback);
		}
	}

	function renderLetters() {
		var label = 'Буква в игре';
		el.letters.textContent = '';
		if (boot.can_letter && !readonly()) {
			el.letter_label.textContent = label;
			el.letters.hidden = false;
			el.letter_hint.hidden = false;
			el.letter_hint.textContent = boot.letter_hint;
			boot.letters.concat([{ letter: null, taken: false }]).forEach(function (item) {
				var b = node('button', item.letter === null ? 'none' : '', item.letter === null ? '—' : item.letter);
				b.type = 'button';
				b.setAttribute('role', 'radio');
				b.setAttribute('aria-checked', item.letter === letter ? 'true' : 'false');
				if (item.letter === null) {
					b.setAttribute('aria-label', 'Без буквы');
				}
				if (item.letter === letter) {
					b.classList.add('on');
				}
				b.disabled = item.taken;
				b.addEventListener('click', function () {
					letter = item.letter;
					letter_touched = true;
					renderLetters();
				});
				el.letters.appendChild(b);
			});
			return;
		}
		el.letters.hidden = true;
		el.letter_hint.hidden = true;
		el.letter_label.textContent = '';
		el.letter_label.appendChild(document.createTextNode(label + ' '));
		el.letter_label.appendChild(node('span', 'ro', letter ? '· ' + letter : '· назначит организатор'));
	}

	function memberRow(card) {
		var row = node('div', 'pl');
		row.appendChild(avatar(card));
		var meta = node('div', 'meta');
		var n = node('div', 'n', card.name);
		if (card.id === captain_id) {
			n.appendChild(document.createTextNode(' 👑'));
		}
		meta.appendChild(n);
		meta.appendChild(node('div', 'u', subLine(card, false)));
		row.appendChild(meta);
		row.appendChild(node('span', 'lv', levelText(card)));
		var removable = !readonly() && (boot.can_remove_any
			|| (card.id !== captain_id && card.id !== boot.actor_id));
		if (!readonly() && card.id !== captain_id) {
			if (boot.can_transfer) {
				var more = node('button', 'icon', '⋯');
				more.type = 'button';
				more.setAttribute('aria-label', 'Действия: ' + card.name);
				more.addEventListener('click', function () {
					openMenu(card);
				});
				row.appendChild(more);
			}
		}
		if (removable) {
			var rm = node('button', 'icon', '✕');
			rm.type = 'button';
			rm.setAttribute('aria-label', 'Убрать ' + card.name);
			rm.addEventListener('click', function () {
				removeMember(card.id);
			});
			row.appendChild(rm);
		}
		return row;
	}

	function resultRow(card, onPick) {
		var b = node('button', 'r');
		b.type = 'button';
		b.appendChild(avatar(card));
		var meta = node('div', 'meta');
		meta.appendChild(node('div', 'n', card.name));
		meta.appendChild(node('div', 'u', subLine(card, true)));
		b.appendChild(meta);
		b.appendChild(node('span', 'lv', levelText(card)));
		var full = members.length >= teamSize();
		if (card.in_team || full) {
			b.disabled = true;
		} else {
			b.appendChild(node('span', 'add', '＋'));
			b.setAttribute('aria-label', 'Добавить ' + card.name);
			b.addEventListener('click', function () {
				onPick(card);
			});
		}
		return b;
	}

	function renderPending() {
		el.pending.textContent = '';
		el.pending.hidden = pending.length === 0 || readonly();
		pending.forEach(function (item, index) {
			var box = node('div', 'pend');
			var head = node('div', 'pend-head');
			head.appendChild(node('span', '', 'Найти «' + item.label + '»'));
			var tools = node('span');
			var find = node('button', 'find', '🔎 искать');
			find.type = 'button';
			find.addEventListener('click', function () {
				el.search.value = item.label;
				runSearch();
				el.search.focus();
			});
			var drop = node('button', 'find', '✕');
			drop.type = 'button';
			drop.setAttribute('aria-label', 'Убрать «' + item.label + '» из списка');
			drop.addEventListener('click', function () {
				pending.splice(index, 1);
				render();
			});
			tools.appendChild(find);
			tools.appendChild(drop);
			head.appendChild(tools);
			box.appendChild(head);
			item.suggestions.forEach(function (card) {
				if (!hasMember(card.id)) {
					box.appendChild(resultRow(card, function (picked) {
						pending.splice(index, 1);
						addMember(picked);
					}));
				}
			});
			el.pending.appendChild(box);
		});
	}

	function renderResults() {
		el.results.textContent = '';
		var q = el.search.value.trim();
		if (q === '' && results.length === 0) {
			el.results.hidden = true;
			return;
		}
		var shown = results.filter(function (card) {
			return !hasMember(card.id);
		});
		if (shown.length === 0) {
			el.results.appendChild(node('div', 'none', q === '' ? 'Начни вводить ник или имя.' : 'Никого не нашла. Попробуй часть ника или выбери из контактов Telegram.'));
		}
		shown.forEach(function (card) {
			el.results.appendChild(resultRow(card, function (picked) {
				addMember(picked);
				el.search.value = '';
				results = [];
				renderResults();
			}));
		});
		el.results.hidden = false;
	}

	function renderSum() {
		var sum = points();
		var max = boot.tournament.max_points;
		el.sum.textContent = '';
		el.sum.appendChild(node('span', '', 'Сумма уровней'));
		if (max === null) {
			el.sum.appendChild(node('span', '', sum + ' · без лимита'));
			el.bar.hidden = true;
			return;
		}
		var over = sum > max;
		el.sum.appendChild(node('span', over ? 'bad' : 'okc', sum + ' из ' + max + (over ? ' ⚠️ выше уровня' : ' ✓')));
		el.bar.hidden = false;
		el.bar.classList.toggle('over', over);
		el.bar_fill.style.width = Math.min(100, Math.round(sum * 100 / max)) + '%';
		if (over && worseThanSaved()) {
			var excess = sum - max;
			setError(el.roster_error, 'Перебор на ' + excess + ' ' + plural(excess, ['балл', 'балла', 'баллов'])
				+ ': лимит турнира ' + max + '. Убери игрока или замени на игрока пониже уровнем.');
			el.roster_error.dataset.live = '1';
		} else if (over) {
			setError(el.roster_error, '⚠️ выше уровня: ' + sum + ' из ' + max
				+ '. Сохранить можно, если не набирать ещё больше баллов.');
			el.roster_error.dataset.live = '1';
		} else if (el.roster_error.dataset.live === '1') {
			setError(el.roster_error, '');
			el.roster_error.dataset.live = '';
		}
	}

	function render() {
		el.roster_label.textContent = 'Состав · ' + members.length + ' из ' + teamSize();
		el.roster.textContent = '';
		members.forEach(function (card) {
			el.roster.appendChild(memberRow(card));
		});
		renderPending();
		var room = !readonly() && members.length < teamSize();
		el.search_box.hidden = !room;
		el.contacts.hidden = !room || !boot.can_contacts;
		if (!room) {
			el.results.hidden = true;
		} else {
			renderResults();
		}
		renderSum();
		el.roster_hint.hidden = members.length >= teamSize();
		el.roster_hint.textContent = 'Пока не хватает ' + (teamSize() - members.length) + ' '
			+ plural(teamSize() - members.length, ['игрока', 'игроков', 'игроков'])
			+ ', команда видна как «собирается».';
		if (boot.can_transfer && !readonly() && members.length > 1) {
			el.roster_hint.hidden = false;
			el.roster_hint.textContent = (members.length < teamSize() ? el.roster_hint.textContent + ' ' : '')
				+ '«⋯» у игрока: сделать капитаном. «✕»: убрать из команды.';
		}
		el.submit.disabled = busy || readonly() || worseThanSaved() || el.name.value.trim() === ''
			|| members.length === 0 || members.length > teamSize();
	}

	function addMember(card) {
		if (hasMember(card.id) || card.in_team || members.length >= teamSize()) {
			return;
		}
		members.push(card);
		if (!captain_id && card.can_captain) {
			// An empty roster (organizer building a team of others): the first player with Telegram.
			captain_id = card.id;
		}
		render();
	}

	function removeMember(id) {
		if (!boot.can_remove_any && (id === captain_id || id === boot.actor_id)) {
			return;
		}
		members = members.filter(function (m) {
			return m.id !== id;
		});
		if (id === captain_id) {
			// The organizer removed the captain: the next player with Telegram takes over.
			captain_id = 0;
			members.some(function (m) {
				if (m.can_captain) {
					captain_id = m.id;
				}
				return m.can_captain;
			});
		}
		render();
	}

	function openMenu(card) {
		menu_id = card.id;
		el.menu_title.textContent = card.name;
		// No Telegram: no messages from the bot, so no captaincy (the server refuses it too).
		el.menu_captain.hidden = card.can_captain === false;
		el.menu.hidden = false;
		(el.menu_captain.hidden ? el.menu_remove : el.menu_captain).focus();
	}

	function closeMenu() {
		el.menu.hidden = true;
		menu_id = 0;
	}

	function runSearch() {
		var q = el.search.value.trim();
		var seq = ++search_seq;
		api('team_search', { q: q }).then(function (data) {
			if (seq !== search_seq) {
				return;
			}
			results = data.players || [];
			renderResults();
		}, function (err) {
			if (seq === search_seq) {
				showErrors(err.data, err.message);
			}
		});
	}

	function formData() {
		return {
			name: el.name.value.trim(),
			player_ids: members.map(function (m) {
				return m.id;
			}),
			captain_id: captain_id,
			letter: letter,
			letter_touched: letter_touched,
			pending: pending.map(function (p) {
				return p.label;
			}),
			opening_id: opening_id
		};
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
		el.disband.disabled = true;
		el.leave.disabled = true;
		if (message) {
			setError(el.banner, message);
		}
	}

	function act(op, extra, busy_text) {
		if (busy || closed) {
			return;
		}
		busy = true;
		clearErrors();
		var label = el.submit.textContent;
		if (op === 'team_submit') {
			el.submit.textContent = busy_text;
		}
		render();
		api(op, extra).then(function () {
			lockForm('');
			if (tg) {
				tg.close();
			}
		}, function (err) {
			busy = false;
			el.submit.textContent = label;
			var reason = err.data && err.data.reason;
			if (reason === 'expired' || reason === 'forbidden') {
				lockForm(err.message);
				return;
			}
			showErrors(err.data, err.message);
			render();
		});
	}

	function confirmThen(text, fn) {
		if (tg && tg.showConfirm) {
			tg.showConfirm(text, function (ok) {
				if (ok) {
					fn();
				}
			});
			return;
		}
		if (window.confirm(text)) {
			fn();
		}
	}

	function start(data) {
		boot = data;
		var team = data.team;
		members = team.members.slice();
		captain_id = team.captain_id;
		letter = team.letter;
		letter_touched = !!team.letter_touched;
		if (data.mode === 'new' && data.can_letter && letter === null && !letter_touched) {
			// The server gives a new team the first free letter, so show it picked.
			var free = (data.letters || []).filter(function (item) {
				return !item.taken;
			});
			letter = free.length ? free[0].letter : null;
		}
		pending = (data.pending || []).slice();
		el.heading.textContent = data.mode === 'new' ? 'Новая команда' : 'Команда «' + data.team_name_saved + '»';
		el.subtitle.textContent = data.tournament.subtitle;
		el.name.value = team.name || '';
		el.name.maxLength = data.name_max;
		el.name.readOnly = data.readonly;
		el.name_hint.hidden = !data.name_hint;
		el.name_hint.textContent = data.name_hint || '';
		el.readonly_hint.hidden = !data.readonly;
		el.submit.hidden = data.readonly;
		el.submit.textContent = data.mode === 'new' ? 'Зарегистрировать команду' : 'Сохранить';
		el.disband.hidden = !data.can_disband;
		el.leave.hidden = !data.can_leave;
		renderLetters();
		el.loading.hidden = true;
		el.fields.hidden = false;
		el.fields.disabled = false;
		render();
	}

	el.name.addEventListener('input', function () {
		setError(el.name_error, '');
		el.name.classList.remove('bad');
		el.name_hint.hidden = true;
		render();
	});
	el.search.addEventListener('input', function () {
		clearTimeout(search_timer);
		search_timer = setTimeout(runSearch, 250);
	});
	el.search.addEventListener('focus', function () {
		if (results.length === 0) {
			runSearch();
		}
	});
	el.contacts.addEventListener('click', function () {
		act('team_contacts', { form: formData() });
	});
	el.menu_captain.addEventListener('click', function () {
		if (menu_id) {
			captain_id = menu_id;
		}
		closeMenu();
		render();
	});
	el.menu_remove.addEventListener('click', function () {
		var id = menu_id;
		closeMenu();
		removeMember(id);
	});
	el.menu_close.addEventListener('click', closeMenu);
	el.menu.addEventListener('click', function (e) {
		if (e.target === el.menu) {
			closeMenu();
		}
	});
	el.disband.addEventListener('click', function () {
		confirmThen('Распустить команду? Игроки получат уведомление, команда снимется с турнира.', function () {
			act('team_disband', {});
		});
	});
	el.leave.addEventListener('click', function () {
		confirmThen('Отменить регистрацию? Ты выйдешь из команды, капитан получит уведомление.', function () {
			act('team_leave', {});
		});
	});
	el.form.addEventListener('submit', function (e) {
		e.preventDefault();
		if (el.submit.disabled) {
			return;
		}
		act('team_submit', { form: formData() }, el.submit.textContent === 'Сохранить' ? 'Сохраняю…' : 'Регистрирую…');
	});

	if (!tg || !tg.initData) {
		el.loading.hidden = true;
		setError(el.banner, 'Открой форму кнопкой в Telegram.');
		return;
	}
	tg.ready();
	tg.expand();
	api('team_bootstrap').then(start, function (err) {
		el.loading.hidden = true;
		lockForm(err.message);
	});
}());
