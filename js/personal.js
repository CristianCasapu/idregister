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
	if (!locked) { return; }

	var esc = function (s) {
		return String(s || '').replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	};
	var url = function (path) {
		return (typeof OC !== 'undefined' && OC.generateUrl) ? OC.generateUrl('/apps/idregister' + path) : '/index.php/apps/idregister' + path;
	};
	var post = function (path, body) {
		return fetch(url(path), {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', requesttoken: (typeof OC !== 'undefined' && OC.requestToken) || '' },
			body: JSON.stringify(body),
		}).then(function (r) { return r.json(); });
	};

	/* an account made the classic way: everything is fixed */
	if (!profile || !profile.express && profile.emailLocked) {
		root.innerHTML = '<div class="section">'
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
		root.innerHTML = '<div class="section">'
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
