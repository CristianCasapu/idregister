/* The profile of an account made with an identity card: what is fixed, and what to add. */
(function () {
	'use strict';
	var root = document.getElementById('idregister-personal');
	if (!root) { return; }
	var t = function (text, vars) {
		return (typeof OC !== 'undefined' && OC.L10N) ? OC.L10N.translate('idregister', text, vars) : text;
	};
	var initial = function (key) {
		try {
			var el = document.querySelector('#initial-state-idregister-' + key);
			return el ? JSON.parse(atob(el.value)) : null;
		} catch (e) { return null; }
	};
	var locked = initial('locked');
	var profile = initial('profile');
	var google = initial('google');
	var phone = initial('phone');

	var esc = function (s) {
		return String(s || '').replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	};
	var url = function (path) {
		return (typeof OC !== 'undefined' && OC.generateUrl) ? OC.generateUrl('/apps/idregister' + path) : '/index.php/apps/idregister' + path;
	};
	var get = function (path, query) {
		var address = url(path);
		if (query) {
			address += (address.indexOf('?') >= 0 ? '&' : '?') + query;
		}
		return fetch(address, { headers: { requesttoken: (typeof OC !== 'undefined' && OC.requestToken) || '' } })
			.then(function (r) { return r.json(); });
	};
	var post = function (path, body) {
		return fetch(url(path), {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', requesttoken: (typeof OC !== 'undefined' && OC.requestToken) || '' },
			body: JSON.stringify(body),
		}).then(function (r) { return r.json(); });
	};

	/* "Continue with Google": tie a Google account to this account, or untie it again */
	function renderGoogle(message) {
		var box = document.getElementById('idreg-google');
		if (!box || !google || !google.enabled) { return; }
		var note = message || (google.message ? google.message.message : '');
		var noteOk = message ? true : !!(google.message && google.message.ok);
		var body;
		if (google.linked) {
			body = '<div class="idreg-admin-row"><strong>' + esc(t('Linked to')) + ':</strong> ' + esc(google.email)
				+ ' <span class="pill ok">' + esc(t('active')) + '</span></div>'
				+ '<p class="muted">' + esc(t('You can sign in with this Google account, without typing a password.')) + '</p>'
				+ '<div class="idreg-admin-row"><button type="button" id="idreg-google-unlink">' + esc(t('Unlink the Google account')) + '</button></div>';
		} else if (!google.accountEmail) {
			body = '<p class="muted">' + esc(t('Confirm your e-mail address above first; then you can link the Google account that uses it.')) + '</p>';
		} else {
			body = '<p class="muted">' + esc(t('Sign in without a password, with the Google account that uses {email}. Any other Google account is refused.', { email: google.accountEmail })) + '</p>'
				+ '<div class="idreg-admin-row"><button type="button" class="primary" id="idreg-google-link">' + esc(t('Link my Google account')) + '</button></div>';
		}
		box.innerHTML = '<div class="section"><h2>' + esc(t('Sign in with Google')) + '</h2>' + body
			+ (note ? '<p class="' + (noteOk ? 'ok' : 'error') + '">' + esc(note) + '</p>' : '') + '</div>';

		var link = document.getElementById('idreg-google-link');
		if (link) {
			link.addEventListener('click', function () {
				link.disabled = true;
				post('/api/google/link', {}).then(function (data) {
					if (data && data.ok && data.url) { window.location = data.url; return; }
					link.disabled = false;
					renderGoogle((data && data.message) || t('Something went wrong. Please try again.'));
				}).catch(function () {
					link.disabled = false;
					renderGoogle(t('Something went wrong. Please try again.'));
				});
			});
		}
		var unlink = document.getElementById('idreg-google-unlink');
		if (unlink) {
			unlink.addEventListener('click', function () {
				if (!window.confirm(t('Sign in with this Google account will stop working. Unlink it?'))) { return; }
				post('/api/google/unlink', {}).then(function (data) {
					google.linked = !(data && data.ok);
					google.message = null;
					renderGoogle(google.linked ? t('Something went wrong. Please try again.') : t('The Google account is unlinked.'));
				});
			});
		}
	}

	/* "Sign in with your phone": the phones paired with this account */
	var pairing = null;
	var pairTimer = null;

	function stopPairing() {
		if (pairTimer) { window.clearTimeout(pairTimer); pairTimer = null; }
		pairing = null;
	}

	function when(seconds) {
		if (!seconds) { return t('never'); }
		return new Date(seconds * 1000).toLocaleString();
	}

	function renderPhone(message, ok) {
		var box = document.getElementById('idreg-phone');
		if (!box || !phone || !phone.enabled) { return; }
		var list = phone.devices.length
			? '<ul class="locked-list">' + phone.devices.map(function (d) {
				return '<li><strong>' + esc(d.name) + '</strong> <span class="muted">'
					+ esc(t('paired {when}, last used {used}', { when: when(d.created), used: when(d.lastUsed) })) + '</span> '
					+ '<button type="button" class="idreg-forget" data-id="' + esc(d.deviceId) + '">' + esc(t('Remove')) + '</button></li>';
			}).join('') + '</ul>'
			: '<p class="muted">' + esc(t('No phone is paired with your account yet.')) + '</p>';

		var body;
		if (pairing) {
			body = '<p class="muted">' + esc(t('In the app, press "Pair this phone" and point it at this code.')) + '</p>'
				+ '<canvas id="idreg-pair-qr" width="1" height="1"></canvas>'
				+ '<div class="idreg-admin-row"><span class="muted" id="idreg-pair-state">' + esc(t('Waiting for the phone …')) + '</span> '
				+ '<button type="button" id="idreg-pair-cancel">' + esc(t('Cancel')) + '</button></div>';
		} else {
			body = '<div class="idreg-admin-row"><label for="idreg-pair-pass">' + esc(t('Your password')) + '</label> '
				+ '<input type="password" id="idreg-pair-pass" autocomplete="current-password"> '
				+ '<button type="button" class="primary" id="idreg-pair-start">' + esc(t('Pair a phone')) + '</button></div>'
				+ '<p class="muted">' + esc(t('The password is asked again so that nobody else can add a phone to your account. The phone keeps a key that never leaves it and only works after your fingerprint.')) + '</p>';
		}
		box.innerHTML = '<div class="section"><h2>' + esc(t('Sign in with your phone')) + '</h2>'
			+ '<p class="muted">' + esc(t('A paired phone can sign you in on any computer: the sign-in page shows a code, you scan it and choose the two digits shown there.')) + '</p>'
			+ list + body
			+ (message ? '<p class="' + (ok ? 'ok' : 'error') + '">' + esc(message) + '</p>' : '') + '</div>';

		if (pairing) {
			document.getElementById('idreg-pair-qr').dataset.payload = pairing.payload;
			window.idregDrawQr(document.getElementById('idreg-pair-qr'), pairing.payload, 220);
			document.getElementById('idreg-pair-cancel').addEventListener('click', function () { stopPairing(); renderPhone(); });
		} else {
			document.getElementById('idreg-pair-start').addEventListener('click', function () {
				var field = document.getElementById('idreg-pair-pass');
				post('/api/device/pair', { password: field.value }).then(function (data) {
					field.value = '';
					if (!data || !data.ok) { renderPhone((data && data.message) || t('Something went wrong. Please try again.'), false); return; }
					pairing = data;
					renderPhone();
					watchPairing();
				}).catch(function () {
					// too many tries in a row, or the server did not answer at all
					renderPhone(t('Too many tries. Please wait a few minutes and try again.'), false);
				});
			});
		}
		Array.prototype.forEach.call(box.querySelectorAll('button.idreg-forget'), function (b) {
			b.addEventListener('click', function () {
				var password = window.prompt(t('Type your password to remove this phone.'));
				if (!password) { return; }
				post('/api/device/forget', { deviceId: b.dataset.id, password: password }).then(function (data) {
					if (!data || !data.ok) { renderPhone((data && data.message) || t('Something went wrong. Please try again.'), false); return; }
					phone.devices = data.devices;
					renderPhone(t('The phone was removed.'), true);
				}).catch(function () {
					renderPhone(t('Too many tries. Please wait a few minutes and try again.'), false);
				});
			});
		});
	}

	function watchPairing() {
		if (!pairing) { return; }
		get('/api/device/pair', 'token=' + encodeURIComponent(pairing.token)).then(function (data) {
			if (!data || !data.ok || 'gone' === data.state) { stopPairing(); renderPhone(t('This pairing code has expired. Ask for a new one.'), false); return; }
			if ('done' === data.state) {
				phone.devices = data.devices;
				stopPairing();
				renderPhone(t('Your phone is paired. You can sign in with it from now on.'), true);
				return;
			}
			pairTimer = window.setTimeout(watchPairing, 2000);
		}).catch(function () { pairTimer = window.setTimeout(watchPairing, 4000); });
	}

	root.innerHTML = '<div id="idreg-personal-main"></div><div id="idreg-google"></div><div id="idreg-phone"></div>';
	var main = document.getElementById('idreg-personal-main');
	renderGoogle('');
	renderPhone();
	if (!locked) { return; }

	/* an account made the classic way: everything is fixed */
	if (!profile || !profile.express && profile.emailLocked) {
		main.innerHTML = '<div class="section">'
			+ '<h2>' + esc(t('Details from your identity card')) + '</h2>'
			+ '<p class="muted">' + esc(t('You registered with your identity card, so these details are fixed. Ask an administrator if something is wrong.')) + '</p>'
			+ '<ul class="locked-list">'
			+ '<li><strong>' + esc(t('Name')) + ':</strong> ' + esc(locked.name) + '</li>'
			+ '<li><strong>' + esc(t('E-mail address')) + ':</strong> ' + esc(locked.email) + '</li>'
			+ (locked.phone ? '<li><strong>' + esc(t('Phone number')) + ':</strong> ' + esc(locked.phone) + '</li>' : '')
			+ '</ul></div>';
		return;
	}

	function render(p) {
		var emailBlock;
		if (p.emailLocked) {
			emailBlock = '<div class="idreg-admin-row"><strong>' + esc(t('E-mail address')) + ':</strong> ' + esc(p.email) + ' <span class="pill ok">' + esc(t('confirmed')) + '</span></div>';
		} else if (p.pendingEmail) {
			emailBlock = '<div class="idreg-admin-row"><label for="idreg-p-code">' + esc(t('We sent a code to {email}. Type it here.', { email: p.pendingEmail })) + '</label></div>'
				+ '<div class="idreg-admin-row"><input type="text" id="idreg-p-code" inputmode="numeric" maxlength="6" autocomplete="one-time-code"> '
				+ '<button type="button" id="idreg-p-confirm">' + esc(t('Confirm')) + '</button> '
				+ '<button type="button" id="idreg-p-change">' + esc(t('Use another address')) + '</button></div>';
		} else {
			emailBlock = '<div class="idreg-admin-row"><label for="idreg-p-email">' + esc(t('E-mail address')) + '</label> '
				+ '<input type="email" id="idreg-p-email" placeholder="name@example.com" autocomplete="email"> '
				+ '<button type="button" id="idreg-p-send">' + esc(t('Send me a code')) + '</button></div>'
				+ '<p class="muted">' + esc(t('A code is sent to the address; once confirmed, the address is fixed.')) + '</p>';
		}
		var phoneBlock = p.phoneLocked
			? '<div class="idreg-admin-row"><strong>' + esc(t('Phone number')) + ':</strong> ' + esc(p.phone) + '</div>'
			: '<div class="idreg-admin-row"><label for="idreg-p-phone">' + esc(t('Phone number')) + '</label> '
				+ '<input type="tel" id="idreg-p-phone" placeholder="07xx xxx xxx" autocomplete="tel" value="' + esc(p.phone) + '"> '
				+ '<button type="button" id="idreg-p-phone-save">' + esc(t('Save')) + '</button></div>'
				+ '<p class="muted">' + esc(t('Once saved, the phone number is fixed.')) + '</p>';
		main.innerHTML = '<div class="section">'
			+ '<h2>' + esc(t('Complete your profile')) + '</h2>'
			+ '<p class="muted">' + esc(t('Your account was created from your identity card. The name is fixed; add the rest here.')) + '</p>'
			+ '<div class="idreg-admin-row"><strong>' + esc(t('Full name')) + ':</strong> ' + esc(p.name) + ' <span class="muted">' + esc(t('(from the identity card)')) + '</span></div>'
			+ '<div class="idreg-admin-row"><strong>' + esc(t('User name')) + ':</strong> <code>' + esc(p.uid) + '</code></div>'
			+ emailBlock + phoneBlock
			+ '<div class="idreg-admin-row"><label for="idreg-p-nick">' + esc(t('Nickname')) + '</label> '
			+ '<input type="text" id="idreg-p-nick" maxlength="40" value="' + esc(p.nickname) + '"> '
			+ '<button type="button" id="idreg-p-nick-save">' + esc(t('Save')) + '</button></div>'
			+ '<div class="idreg-admin-row"><span id="idreg-p-msg" class="muted"></span></div>'
			+ '</div>';

		var msg = function (text, ok) { var m = document.getElementById('idreg-p-msg'); m.textContent = text; m.className = ok ? 'ok' : 'error'; };
		var handle = function (promise, done) {
			promise.then(function (data) {
				if (!data.ok) { msg(data.message, false); return; }
				render(data);
				if (done) { msg(done, true); }
			}).catch(function () { msg(t('Something went wrong. Please try again.'), false); });
		};
		var on = function (id, fn) { var el = document.getElementById(id); if (el) { el.addEventListener('click', fn); } };
		on('idreg-p-send', function () {
			handle(post('/api/profile/email', { email: document.getElementById('idreg-p-email').value.trim(), language: document.documentElement.lang || 'en' }), t('A code is on its way.'));
		});
		on('idreg-p-confirm', function () {
			handle(post('/api/profile/email/confirm', { code: document.getElementById('idreg-p-code').value.trim() }), t('Your e-mail address is confirmed.'));
		});
		on('idreg-p-change', function () { handle(post('/api/profile/email', { email: '' })); render(Object.assign({}, p, { pendingEmail: '' })); });
		on('idreg-p-phone-save', function () {
			handle(post('/api/profile/phone', { phone: document.getElementById('idreg-p-phone').value.trim() }), t('Phone number saved.'));
		});
		on('idreg-p-nick-save', function () {
			handle(post('/api/profile/nickname', { nickname: document.getElementById('idreg-p-nick').value }), t('Nickname saved.'));
		});
	}
	render(profile);
})();
