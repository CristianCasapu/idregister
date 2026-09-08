/* The page the confirmation link opens: choose a password, then the account is created. */
(function () {
	'use strict';

	var t = function (text, vars) {
		return (typeof OC !== 'undefined' && OC.L10N) ? OC.L10N.translate('idregister', text, vars) : text;
	};
	var $ = function (id) { return document.getElementById(id); };
	if (!$('idreg-finish')) { return; }

	var initial = function (key, fallback) {
		try {
			var el = document.querySelector('#initial-state-idregister-' + key);
			return el ? JSON.parse(atob(el.value)) : fallback;
		} catch (e) { return fallback; }
	};
	var token = initial('verifyToken', '');
	var conditions = initial('conditions', { minPasswordLength: 10 });
	var loginUrl = initial('loginUrl', '/login');
	var inFlight = 0;

	function url(path) {
		return (typeof OC !== 'undefined' && OC.generateUrl) ? OC.generateUrl('/apps/idregister' + path) : '/index.php/apps/idregister' + path;
	}
	function message(text, kind) {
		var box = $('idreg-message');
		if (!box) { return; }
		box.hidden = !text;
		box.className = 'idreg-message' + (kind ? ' ' + kind : '');
		box.textContent = text || '';
	}
	function busy(on) {
		inFlight += on ? 1 : -1;
		if (inFlight < 0) { inFlight = 0; }
		$('idreg-spinner').hidden = inFlight === 0;
		$('idreg-finish').disabled = inFlight > 0 || !ready;
	}

	var ready = false;
	var check = window.idregPassword.attach({
		input: 'idreg-password', repeat: 'idreg-password2', bar: 'idreg-meter-bar',
		hint: 'idreg-password-hint', rules: 'idreg-rules', eye: 'idreg-eye',
		minLength: conditions.minPasswordLength,
		onChange: function (ok) { ready = ok; $('idreg-finish').disabled = !ok || inFlight > 0; },
	});

	var generateButton = $('idreg-generate');
	if (generateButton) {
		generateButton.addEventListener('click', function () {
			window.idregPassword.fill({ input: 'idreg-password', repeat: 'idreg-password2', eye: 'idreg-eye', minLength: conditions.minPasswordLength });
		});
	}

	$('idreg-finish').addEventListener('click', function () {
		if (!check()) { return; }
		busy(true);
		fetch(url('/api/finish'), {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', requesttoken: (typeof OC !== 'undefined' && OC.requestToken) || '' },
			body: JSON.stringify({ token: token, password: $('idreg-password').value }),
		}).then(function (r) { return r.json(); }).then(function (data) {
			busy(false);
			if (!data.ok) { message(data.message, 'error'); return; }
			if (data.status === 'awaiting_approval') {
				document.querySelector('.idreg-card').innerHTML =
					'<h1>' + t('Thank you') + '</h1><p class="idreg-done">'
					+ t('An administrator still has to let you in; you will get an e-mail when the account is open.') + '</p>';
				return;
			}
			window.location.href = loginUrl;
		}).catch(function () {
			busy(false);
			message(t('Something went wrong. Please try again.'), 'error');
		});
	});
})();
