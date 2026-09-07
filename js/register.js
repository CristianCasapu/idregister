/* Registration with an identity document. Plain JavaScript so the page stays small on a phone. */
(function () {
	'use strict';

	var t = function (text, vars) {
		return (typeof OC !== 'undefined' && OC.L10N) ? OC.L10N.translate('idregister', text, vars) : text;
	};
	var $ = function (id) { return document.getElementById(id); };
	var root = $('idregister');
	if (!root) { return; }

	var initial = function (key, fallback) {
		try {
			var el = document.querySelector('#initial-state-idregister-' + key);
			return el ? JSON.parse(atob(el.value)) : fallback;
		} catch (e) { return fallback; }
	};

	var enabled = initial('registrationOpen', false);
	var ocrReady = initial('ocr', false);
	var loginUrl = initial('loginUrl', '/login');
	var conditions = initial('conditions', {
		minPasswordLength: 10, requirePhone: true, minAge: 0, termsUrl: '',
		requireSelfie: true, acceptIdCard: true, acceptDrivingLicence: true,
	});
	var serverSaysMobile = initial('mobile', false);
	var mobileOnly = initial('mobileOnly', true);
	var handoff = initial('handoff', '');

	var state = { file: null, selfie: null, card: null, scanId: '', token: '', language: (document.documentElement.lang || 'en') };

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

	function busy(on, what) {
		$('idreg-spinner').hidden = !on;
		if (on && what) { $('idreg-spinner-text').textContent = what; }
		Array.prototype.forEach.call(root.querySelectorAll('button'), function (b) { b.disabled = on; });
		if (!on) {
			$('idreg-scan').disabled = !state.file;
			$('idreg-check-selfie').disabled = !state.selfie;
		}
	}

	function step(n) {
		Array.prototype.forEach.call(root.querySelectorAll('.idreg-step'), function (s) {
			s.hidden = Number(s.dataset.step) !== n;
		});
		Array.prototype.forEach.call(root.querySelectorAll('.dot'), function (d) {
			d.classList.toggle('on', Number(d.dataset.dot) <= Math.min(n, 5));
		});
		root.querySelector('.idreg-steps').hidden = n === 0;
		message('');
		window.scrollTo({ top: 0, behavior: 'smooth' });
	}

	function post(path, body, isForm) {
		var headers = { requesttoken: (typeof OC !== 'undefined' && OC.requestToken) || '' };
		if (!isForm) { headers['Content-Type'] = 'application/json'; }
		return fetch(url(path), { method: 'POST', headers: headers, body: isForm ? body : JSON.stringify(body) })
			.then(function (r) { return r.json(); });
	}

	/** A phone or a tablet? The server guesses from the User-Agent, the browser knows about its touch screen. */
	function isHandheld() {
		var touch = (navigator.maxTouchPoints || 0) > 0 || 'ontouchstart' in window;
		var small = Math.min(screen.width, screen.height) <= 1024;
		return serverSaysMobile || (touch && small);
	}

	if (!enabled) {
		message(t('Registration is currently closed.'), 'error');
		Array.prototype.forEach.call(root.querySelectorAll('.idreg-step'), function (s) { s.hidden = true; });
		root.querySelector('.idreg-steps').hidden = true;
		return;
	}

	/* ---- step 0: hand over from a computer to a phone ---- */
	function drawQr(text) {
		var canvas = $('idreg-qr');
		try {
			var qr = qrcode(0, 'M');
			qr.addData(text);
			qr.make();
			var count = qr.getModuleCount();
			var quiet = 4;
			var cell = Math.max(4, Math.floor(Math.min(300, window.innerWidth - 80) / (count + quiet * 2)));
			var size = (count + quiet * 2) * cell;
			canvas.width = size;
			canvas.height = size;
			var ctx = canvas.getContext('2d');
			ctx.fillStyle = '#fff';
			ctx.fillRect(0, 0, size, size);
			ctx.fillStyle = '#000';
			for (var r = 0; r < count; r++) {
				for (var c = 0; c < count; c++) {
					if (qr.isDark(r, c)) {
						ctx.fillRect((c + quiet) * cell, (r + quiet) * cell, cell, cell);
					}
				}
			}
		} catch (e) {
			console.error(e);
			canvas.hidden = true;
		}
	}

	function followHandoff(token) {
		var order = ['waiting', 'opened', 'document', 'registered', 'confirmed'];
		var timer = window.setInterval(function () {
			fetch(url('/api/handoff/' + token)).then(function (r) { return r.json(); }).then(function (data) {
				var reached = order.indexOf(data.state);
				Array.prototype.forEach.call($('idreg-progress').children, function (li) {
					li.classList.toggle('done', order.indexOf(li.dataset.state) <= reached);
				});
				if (data.state === 'confirmed') {
					window.clearInterval(timer);
					message(t('The account of {name} is ready. You can sign in.', { name: data.name || '' }), 'ok');
				}
				if (data.state === 'expired') { window.clearInterval(timer); }
			}).catch(function () {});
		}, 3000);
	}

	if (mobileOnly && !isHandheld()) {
		step(0);
		post('/api/handoff', {}).then(function (data) {
			if (!data.ok) { message(t('Something went wrong. Please try again.'), 'error'); return; }
			drawQr(data.url);
			$('idreg-qr-url').textContent = data.url;
			followHandoff(data.token);
		}).catch(function () {
			message(t('Something went wrong. Please try again.'), 'error');
		});
		return;
	}

	if (!ocrReady) {
		message(t('The card reader is not available on this server. Please tell the administrator.'), 'error');
	}

	/* ---- step 1: the document ---- */
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
		busy(true, t('Reading the document …'));
		var form = new FormData();
		form.append('image', state.file, 'document.jpg');
		if (handoff) { form.append('handoff', handoff); }
		post('/api/scan', form, true).then(function (data) {
			busy(false);
			if (!data.ok) {
				message(data.message || t('The identity card could not be read. Try again with more light and the whole card in the frame.'), 'error');
				return;
			}
			state.card = data;
			state.scanId = data.scanId;
			$('idreg-given').value = data.givenNames;
			$('idreg-surname').value = data.surname;
			$('idreg-doc-type').textContent = data.type === 'driving_licence'
				? t('Read from a driving licence.')
				: t('Read from an identity card.');
			step(2);
		}).catch(function () {
			busy(false);
			message(t('Something went wrong. Please try again.'), 'error');
		});
	});

	/* ---- step 2 ---- */
	$('idreg-again').addEventListener('click', function () { step(1); });
	$('idreg-confirm-card').addEventListener('click', function () {
		step(state.card && state.card.needsSelfie ? 3 : 4);
	});

	/* ---- step 3: the selfie ---- */
	$('idreg-selfie-file').addEventListener('change', function (event) {
		var file = event.target.files && event.target.files[0];
		if (!file) { return; }
		state.selfie = file;
		var preview = $('idreg-selfie-preview');
		preview.src = URL.createObjectURL(file);
		preview.hidden = false;
		$('idreg-selfie-text').textContent = t('Take another picture');
		$('idreg-check-selfie').disabled = false;
		message('');
	});

	$('idreg-back-selfie').addEventListener('click', function () { step(2); });

	$('idreg-check-selfie').addEventListener('click', function () {
		if (!state.selfie) { return; }
		busy(true, t('Comparing with the photo on the document …'));
		var form = new FormData();
		form.append('image', state.selfie, 'selfie.jpg');
		form.append('scanId', state.scanId);
		post('/api/selfie', form, true).then(function (data) {
			busy(false);
			if (!data.ok) { message(data.message, 'error'); return; }
			if (data.review) {
				message(t('We are not completely sure it is the same person, so an administrator will look at your registration.'), null);
			}
			step(4);
		}).catch(function () {
			busy(false);
			message(t('Something went wrong. Please try again.'), 'error');
		});
	});

	/* ---- step 4: the account ---- */
	$('idreg-back-2').addEventListener('click', function () {
		step(state.card && state.card.needsSelfie ? 3 : 2);
	});

	$('idreg-submit').addEventListener('click', function () {
		var email = $('idreg-email').value.trim();
		var phone = $('idreg-phone').value.trim();
		var password = $('idreg-password').value;
		if (!email || email.indexOf('@') < 1) { message(t('This e-mail address is not valid.'), 'error'); return; }
		if ((conditions.requirePhone || phone) && phone.replace(/\D/g, '').length < 9) { message(t('This phone number is not valid.'), 'error'); return; }
		if (password.length < conditions.minPasswordLength) { message(t('The password is too short.'), 'error'); return; }
		if (!$('idreg-terms').checked) { message(t('Please accept how your data is used.'), 'error'); return; }

		busy(true, t('Creating the account …'));
		post('/api/register', {
			scanId: state.scanId,
			handoff: handoff,
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
			step(5);
		}).catch(function () {
			busy(false);
			message(t('Something went wrong. Please try again.'), 'error');
		});
	});

	/* ---- step 5: confirmation ---- */
	$('idreg-verify').addEventListener('click', function () {
		var code = $('idreg-code').value.trim();
		if (code.length < 6) { message(t('This code is not correct.'), 'error'); return; }
		busy(true);
		post('/api/verify', { token: state.token, code: code, handoff: handoff }).then(function (data) {
			busy(false);
			if (!data.ok) { message(data.message, 'error'); return; }
			$('idreg-done-text').textContent = data.status === 'awaiting_approval'
				? t('Thank you. An administrator still has to let you in; you will get an e-mail when the account is open.')
				: t('Your account is ready. Your user name is {uid}.', { uid: data.uid });
			$('idreg-login').href = loginUrl;
			$('idreg-login').hidden = data.status === 'awaiting_approval';
			step(6);
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

	/* ---- the form follows what the administrator asked for ---- */
	$('idreg-password').setAttribute('minlength', String(conditions.minPasswordLength));
	$('idreg-password-hint').textContent = t('At least {n} characters.', { n: conditions.minPasswordLength });
	if (!conditions.requirePhone) {
		$('idreg-phone').required = false;
		$('idreg-phone').previousElementSibling.textContent = t('Phone number (optional)');
	}
	if (conditions.termsUrl) {
		var termsLink = $('idreg-terms-link');
		termsLink.href = conditions.termsUrl;
		termsLink.hidden = false;
	}
	if (conditions.acceptDrivingLicence && conditions.acceptIdCard) {
		$('idreg-doc-lead').textContent = t('Take a picture of your identity card or your driving licence. We read your name from it and then delete the picture — it is never stored.');
	} else if (conditions.acceptDrivingLicence) {
		$('idreg-doc-lead').textContent = t('Take a picture of your driving licence. We read your name from it and then delete the picture — it is never stored.');
	}

	step(1);
})();
