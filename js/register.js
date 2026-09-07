/* Registration with an identity card. Plain JavaScript so the page stays small on a phone. */
(function () {
	'use strict';

	var t = function (text, vars) {
		return (typeof OC !== 'undefined' && OC.L10N) ? OC.L10N.translate('idregister', text, vars) : text;
	};
	var state = { file: null, card: null, token: '', language: (document.documentElement.lang || 'en') };
	var $ = function (id) { return document.getElementById(id); };
	var root = $('idregister');
	if (!root) { return; }

	var initial = function (key, fallback) {
		try {
			var el = document.querySelector('#initial-state-idregister-' + key);
			return el ? JSON.parse(atob(el.value)) : fallback;
		} catch (e) { return fallback; }
	};

	var enabled = initial('enabled', false);
	var ocrReady = initial('ocr', false);
	var requireApproval = initial('requireApproval', false);
	var loginUrl = initial('loginUrl', '/login');

	function url(path) {
		return (typeof OC !== 'undefined' && OC.generateUrl) ? OC.generateUrl('/apps/idregister' + path) : '/index.php/apps/idregister' + path;
	}

	function message(text, kind) {
		var box = $('idreg-message');
		if (!text) { box.hidden = true; return; }
		box.hidden = false;
		box.className = 'idreg-message' + (kind ? ' ' + kind : '');
		box.textContent = text;
		box.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
	}

	function busy(on) {
		$('idreg-spinner').hidden = !on;
		Array.prototype.forEach.call(root.querySelectorAll('button'), function (b) { b.disabled = on; });
		if (!on) { $('idreg-scan').disabled = !state.file; }
	}

	function step(n) {
		Array.prototype.forEach.call(root.querySelectorAll('.idreg-step'), function (s) {
			s.hidden = Number(s.dataset.step) !== n;
		});
		Array.prototype.forEach.call(root.querySelectorAll('.dot'), function (d) {
			d.classList.toggle('on', Number(d.dataset.dot) <= Math.min(n, 4));
		});
		message('');
		window.scrollTo({ top: 0, behavior: 'smooth' });
	}

	function post(path, body, isForm) {
		var headers = { requesttoken: (typeof OC !== 'undefined' && OC.requestToken) || '' };
		if (!isForm) { headers['Content-Type'] = 'application/json'; }
		return fetch(url(path), {
			method: 'POST',
			headers: headers,
			body: isForm ? body : JSON.stringify(body),
		}).then(function (r) { return r.json(); });
	}

	if (!enabled) {
		message(t('Registration is currently closed.'), 'error');
		Array.prototype.forEach.call(root.querySelectorAll('.idreg-step'), function (s) { s.hidden = true; });
		return;
	}
	if (!ocrReady) {
		message(t('The card reader is not available on this server. Please tell the administrator.'), 'error');
	}

	/* ---- step 1: the picture ---- */
	$('idreg-file').addEventListener('change', function (event) {
		var file = event.target.files && event.target.files[0];
		if (!file) { return; }
		state.file = file;
		var preview = $('idreg-preview');
		preview.src = URL.createObjectURL(file);
		preview.hidden = false;
		$('idreg-capture-text').textContent = t('Take another picture');
		$('idreg-scan').disabled = false;
		message('');
	});

	$('idreg-scan').addEventListener('click', function () {
		if (!state.file) { return; }
		busy(true);
		var form = new FormData();
		form.append('image', state.file, 'card.jpg');
		post('/api/scan', form, true).then(function (data) {
			busy(false);
			if (!data.ok) {
				message(data.message || t('The identity card could not be read. Try again with more light and the whole card in the frame.'), 'error');
				return;
			}
			state.card = data;
			$('idreg-given').value = data.givenNames;
			$('idreg-surname').value = data.surname;
			step(2);
		}).catch(function () {
			busy(false);
			message(t('Something went wrong. Please try again.'), 'error');
		});
	});

	/* ---- step 2 ---- */
	$('idreg-again').addEventListener('click', function () { step(1); });
	$('idreg-confirm-card').addEventListener('click', function () { step(3); });

	/* ---- step 3: the account ---- */
	$('idreg-back-2').addEventListener('click', function () { step(2); });

	$('idreg-submit').addEventListener('click', function () {
		var email = $('idreg-email').value.trim();
		var phone = $('idreg-phone').value.trim();
		var password = $('idreg-password').value;
		if (!email || email.indexOf('@') < 1) { message(t('This e-mail address is not valid.'), 'error'); return; }
		if (phone.replace(/\D/g, '').length < 9) { message(t('This phone number is not valid.'), 'error'); return; }
		if (password.length < 10) { message(t('The password must have at least 10 characters.'), 'error'); return; }
		if (!$('idreg-terms').checked) { message(t('Please accept how your data is used.'), 'error'); return; }

		busy(true);
		post('/api/register', {
			surname: state.card.surname,
			givenNames: state.card.givenNames,
			cnp: state.card.cnp || '',
			email: email,
			phone: phone,
			password: password,
			language: state.language,
			terms: true,
		}).then(function (data) {
			busy(false);
			if (!data.ok) { message(data.message, 'error'); return; }
			state.token = data.token;
			$('idreg-sent').textContent = t('We sent a code to {email}. Type it here, or open the link in that e-mail.', { email: data.email });
			step(4);
		}).catch(function () {
			busy(false);
			message(t('Something went wrong. Please try again.'), 'error');
		});
	});

	/* ---- step 4: confirmation ---- */
	$('idreg-verify').addEventListener('click', function () {
		var code = $('idreg-code').value.trim();
		if (code.length < 6) { message(t('This code is not correct.'), 'error'); return; }
		busy(true);
		post('/api/verify', { token: state.token, code: code }).then(function (data) {
			busy(false);
			if (!data.ok) { message(data.message, 'error'); return; }
			$('idreg-done-text').textContent = data.status === 'awaiting_approval'
				? t('Thank you. An administrator still has to let you in; you will get an e-mail when the account is open.')
				: t('Your account is ready. Your user name is {uid}.', { uid: data.uid });
			$('idreg-login').href = loginUrl;
			$('idreg-login').hidden = data.status === 'awaiting_approval';
			step(5);
		}).catch(function () {
			busy(false);
			message(t('Something went wrong. Please try again.'), 'error');
		});
	});

	$('idreg-resend').addEventListener('click', function () {
		busy(true);
		post('/api/resend', { token: state.token, language: state.language }).then(function (data) {
			busy(false);
			message(data.ok ? t('A new code is on its way.') : data.message, data.ok ? 'ok' : 'error');
		}).catch(function () {
			busy(false);
			message(t('Something went wrong. Please try again.'), 'error');
		});
	});

	$('idreg-code').addEventListener('input', function (event) {
		event.target.value = event.target.value.replace(/\D/g, '').slice(0, 6);
		if (event.target.value.length === 6) { $('idreg-verify').click(); }
	});

	step(1);
	void requireApproval;
})();
