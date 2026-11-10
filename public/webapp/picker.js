(function () {
	var tg = window.Telegram && window.Telegram.WebApp;
	var params = new URLSearchParams(window.location.search);
	var ctx = params.get('ctx') || '';
	var apiUrl = '../webapp_api.php';
	var state = {
		action: 'show_level',
		maxSelect: 1,
		canSetLevel: false,
		levels: [],
		players: [],
		selected: {},
		level: null,
		timer: null
	};

	var elQ = document.getElementById('q');
	var elList = document.getElementById('list');
	var elErr = document.getElementById('err');
	var elLevelBox = document.getElementById('level-box');
	var elLevels = document.getElementById('levels');

	function showError(msg) {
		elErr.hidden = !msg;
		elErr.textContent = msg || '';
	}

	function initData() {
		return (tg && tg.initData) ? tg.initData : '';
	}

	function api(op, extra) {
		var body = Object.assign({ op: op, ctx: ctx, init_data: initData() }, extra || {});
		return fetch(apiUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(body)
		}).then(function (res) {
			return res.json().then(function (data) {
				if (!res.ok || !data || data.ok === false) {
					throw new Error((data && data.error) || 'Ошибка запроса');
				}
				return data;
			});
		});
	}

	function selectedIds() {
		return Object.keys(state.selected).map(function (k) { return parseInt(k, 10); });
	}

	function updateMain() {
		if (!tg || !tg.MainButton) {
			return;
		}
		var n = selectedIds().length;
		var label = 'Готово';
		if (n > 1) {
			label = 'Готово (' + n + ')';
		}
		tg.MainButton.setText(label);
		if (n > 0) {
			tg.MainButton.show();
			tg.MainButton.enable();
		} else {
			tg.MainButton.hide();
		}
	}

	function toggle(id) {
		if (state.selected[id]) {
			delete state.selected[id];
		} else {
			if (state.maxSelect <= 1) {
				state.selected = {};
			} else if (selectedIds().length >= state.maxSelect) {
				showError('Можно выбрать не больше ' + state.maxSelect + '.');
				return;
			}
			state.selected[id] = true;
		}
		showError('');
		renderList();
		renderLevels();
		updateMain();
	}

	function renderList() {
		if (state.players.length === 0) {
			elList.innerHTML = '<div class="empty">Никого не нашла. Попробуй другой запрос.</div>';
			return;
		}
		elList.innerHTML = '';
		state.players.forEach(function (p) {
			var row = document.createElement('div');
			row.className = 'row' + (state.selected[p.id] ? ' on' : '');
			row.addEventListener('click', function () { toggle(p.id); });
			var who = p.ign ? p.ign : p.label;
			var sub = [];
			if (p.username) {
				sub.push('@' + p.username);
			} else if (p.display_name && p.display_name !== who) {
				sub.push(p.display_name);
			}
			if (p.ign && p.label && p.label !== p.ign) {
				// label already "IGN (@user)" — skip duplicate
			}
			var level = (p.level_name || '') + ' (' + p.level + ')' + (p.level_explicit ? '' : ', по умолчанию');
			row.innerHTML =
				'<span class="mark"></span>' +
				'<div class="meta"><div class="ign"></div><div class="sub"></div></div>' +
				'<div class="lvl"></div>';
			row.querySelector('.ign').textContent = who;
			row.querySelector('.sub').textContent = sub.join(' · ');
			row.querySelector('.lvl').textContent = level;
			elList.appendChild(row);
		});
	}

	function renderLevels() {
		var show = state.canSetLevel && state.maxSelect === 1 && selectedIds().length === 1;
		elLevelBox.hidden = !show;
		if (!show) {
			return;
		}
		elLevels.innerHTML = '';
		var none = document.createElement('button');
		none.type = 'button';
		none.className = 'chip' + (state.level === null ? ' on' : '');
		none.textContent = 'Только показать';
		none.addEventListener('click', function () {
			state.level = null;
			renderLevels();
		});
		elLevels.appendChild(none);
		(state.levels || []).forEach(function (opt) {
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'chip' + (state.level === opt.level ? ' on' : '');
			b.textContent = opt.name + ' (' + opt.level + ')';
			b.addEventListener('click', function () {
				state.level = opt.level;
				renderLevels();
			});
			elLevels.appendChild(b);
		});
	}

	function search(q) {
		api('search', { q: q }).then(function (data) {
			state.players = data.players || [];
			renderList();
		}).catch(function (e) {
			showError(e.message);
		});
	}

	function submit() {
		var ids = selectedIds();
		if (ids.length === 0) {
			return;
		}
		if (tg && tg.MainButton) {
			tg.MainButton.showProgress();
			tg.MainButton.disable();
		}
		var payload = { player_ids: ids };
		if (state.canSetLevel && state.level !== null) {
			payload.level = state.level;
		}
		api('submit', payload).then(function () {
			if (tg) {
				tg.close();
			}
		}).catch(function (e) {
			showError(e.message);
			if (tg && tg.MainButton) {
				tg.MainButton.hideProgress();
				updateMain();
			}
		});
	}

	if (!tg || !initData()) {
		showError('Открой подбор из лички с ботом в Telegram.');
		elQ.disabled = true;
		return;
	}
	tg.ready();
	tg.expand();
	if (tg.MainButton) {
		tg.MainButton.setText('Готово');
		tg.MainButton.onClick(submit);
	}

	api('bootstrap').then(function (data) {
		state.action = data.action || state.action;
		state.maxSelect = data.max_select || 1;
		state.canSetLevel = !!data.can_set_level;
		state.levels = data.levels || [];
		if (state.maxSelect > 1) {
			document.getElementById('hint').textContent =
				'Можно выбрать до ' + state.maxSelect + ' игроков. Ищи по нику, @username или имени.';
		}
		search('');
	}).catch(function (e) {
		showError(e.message);
		elQ.disabled = true;
	});

	elQ.addEventListener('input', function () {
		clearTimeout(state.timer);
		state.timer = setTimeout(function () {
			search(elQ.value);
		}, 200);
	});
})();
