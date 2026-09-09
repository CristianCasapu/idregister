/* The page that waits for a paired phone to approve the sign-in. */
(function () {
	'use strict';
	var root = document.getElementById('idregister-phone');
	if (!root) { return; }

	var t = function (text, vars) {
		return (typeof OC !== 'undefined' && OC.L10N) ? OC.L10N.translate('idregister', text, vars) : text;
	};
	var initial = function (key, fallback) {
		try {
			var el = document.querySelector('#initial-state-idregister-' + key);
			return el ? JSON.parse(atob(el.value)) : fallback;
		} catch (e) { return fallback; }
	};
	var url = function (path) {
		return (typeof OC !== 'undefined' && OC.generateUrl) ? OC.generateUrl('/apps/idregister' + path) : '/index.php/apps/idregister' + path;
	};
	var post = function (path, body) {
		return fetch(url(path), {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', requesttoken: (typeof OC !== 'undefined' && OC.requestToken) || '' },
			body: JSON.stringify(body || {}),
		}).then(function (r) { return r.json(); });
	};
	var $ = function (id) { return document.getElementById(id); };

	var redirect = initial('phoneRedirect', '');
	var enabled = initial('phoneEnabled', false);
	var request = null;
	var timer = null;
	var ticker = null;

	function message(text, kind) {
		var box = $('idreg-phone-message');
		box.textContent = text || '';
		box.className = 'idreg-message' + (kind ? ' ' + kind : '');
		box.hidden = !text;
	}

	function stop() {
		if (timer) { window.clearTimeout(timer); timer = null; }
		if (ticker) { window.clearInterval(ticker); ticker = null; }
	}

	function expired(text) {
		stop();
		$('idreg-phone-qr').hidden = true;
		$('idreg-phone-number').hidden = true;
		$('idreg-phone-countdown').textContent = '';
		$('idreg-phone-again').hidden = false;
		message(text, 'error');
	}

	function countdown() {
		var left = Math.max(0, Math.round((request.expires * 1000 - Date.now()) / 1000));
		$('idreg-phone-countdown').textContent = left > 0 ? t('This code is good for {seconds} seconds.', { seconds: left }) : '';
		if (0 === left) { expired(t('This code has expired.')); }
	}

	function poll() {
		post('/api/device/login/poll', { requestId: request.requestId, secret: request.secret }).then(function (data) {
			if (!data || !data.ok) { expired((data && data.message) || t('Something went wrong. Please try again.')); return; }
			if ('approved' === data.state && data.url) {
				stop();
				message(t('Your phone said yes. Signing you in …'), 'ok');
				window.location = data.url;
				return;
			}
			if ('denied' === data.state) { expired(t('The sign-in was refused on the phone.')); return; }
			if ('expired' === data.state || 'taken' === data.state) { expired(t('This code has expired.')); return; }
			timer = window.setTimeout(poll, 2000);
		}).catch(function () {
			timer = window.setTimeout(poll, 4000);
		});
	}

	function start() {
		stop();
		message('');
		$('idreg-phone-again').hidden = true;
		post('/api/device/login', { redirect: redirect }).then(function (data) {
			if (!data || !data.ok) { expired((data && data.message) || t('Something went wrong. Please try again.')); return; }
			request = data;
			$('idreg-phone-qr').hidden = false;
			// the same text the code holds, for a phone that cannot read the picture
			$('idreg-phone-qr').dataset.payload = data.payload;
			window.idregDrawQr($('idreg-phone-qr'), data.payload, 280);
			$('idreg-phone-digits').textContent = data.number;
			$('idreg-phone-number').hidden = false;
			countdown();
			ticker = window.setInterval(countdown, 1000);
			timer = window.setTimeout(poll, 2000);
		}).catch(function () {
			expired(t('Something went wrong. Please try again.'));
		});
	}

	$('idreg-phone-again').addEventListener('click', start);
	if (!enabled) {
		expired(t('Signing in with a phone is not available here.'));
	} else {
		start();
	}
})();
