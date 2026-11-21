(function () {
	'use strict';

	// Entry page of the Direct Mini App (BotFather /newapp URL: …/webapp/app.html). It looks at the
	// start_param of the link t.me/<bot>/<short name>?startapp=<param> and opens the right form,
	// keeping the launch data in the URL hash so the form authenticates with the same signed initData.
	var ROUTES = [
		{ re: /^p\d+$/, page: 'player.html' }
	];
	var tg = window.Telegram && window.Telegram.WebApp;
	var param = tg && tg.initDataUnsafe && tg.initDataUnsafe.start_param ? String(tg.initDataUnsafe.start_param) : '';

	function fail(text) {
		document.getElementById('msg').hidden = true;
		var box = document.getElementById('error');
		box.textContent = text;
		box.hidden = false;
	}

	if (!tg || !tg.initData) {
		fail('Открой приложение ссылкой из Telegram.');
		return;
	}
	tg.ready();
	for (var i = 0; i < ROUTES.length; i++) {
		if (ROUTES[i].re.test(param)) {
			window.location.replace(ROUTES[i].page + window.location.hash);
			return;
		}
	}
	fail('Не поняла, что открыть. Нажми ссылку ещё раз, например ✏️ в списке /players.');
}());
