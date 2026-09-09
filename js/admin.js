/* Administration of IDRegister */
(function () {
	'use strict';

	var root = document.getElementById('idregister-admin');
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
	var call = function (method, path, body) {
		return fetch(url(path), {
			method: method,
			headers: { 'Content-Type': 'application/json', requesttoken: (typeof OC !== 'undefined' && OC.requestToken) || '' },
			body: body ? JSON.stringify(body) : undefined,
		}).then(function (r) { return r.json(); });
	};

	var config = initial('config', {});
	var google = initial('google', { secretSet: false, ready: false, redirectUri: '' });
	var phoneServer = initial('phoneServer', '');
	var ocr = initial('ocr', { ok: false, version: '', languages: [], missing: [] });
	var faces = initial('faces', { available: false, python: '', root: '' });
	var reader = initial('reader', { installed: false, canInstall: false, reason: '', install: { state: 'idle' } });
	var readerTimer = null;
	var groups = initial('groups', []);
	var registerUrl = initial('registerUrl', '');
	var registrations = [];

	function esc(s) {
		return String(s === undefined || s === null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function save(patch, extra) {
		Object.assign(config, patch);
		call('PUT', '/api/admin/config', Object.assign({ config: patch }, extra || {})).then(function (data) {
			if (data && data.config) { config = data.config; }
			if (data && data.google) { google = data.google; renderGoogle(); }
		});
	}

	/* The Google client secret goes up on its own and never comes back down. */
	function saveSecret(secret) {
		save({}, { googleClientSecret: secret });
	}

	function renderGoogle() {
		var box = document.getElementById('idreg-google');
		if (!box) { return; }
		box.innerHTML = ''
			+ '<div class="idreg-admin-row"><label><input type="checkbox" id="cfg-google"' + (config.googleEnabled ? ' checked' : '') + '> '
			+ esc(t('Let accounts sign in with a linked Google account')) + '</label></div>'
			+ '<p class="muted">' + esc(t('Nobody registers with Google: an account is created with an identity card as before, and its owner ties a Google account to it in their personal settings. Only a Google account whose verified address is the address already confirmed here can be tied to it.')) + '</p>'
			+ '<div class="idreg-admin-row"><label>' + esc(t('Client ID')) + ' <input type="text" id="cfg-googleid" size="52" value="' + esc(config.googleClientId || '') + '" placeholder="…apps.googleusercontent.com"></label></div>'
			+ '<div class="idreg-admin-row"><label>' + esc(t('Client secret')) + ' <input type="password" id="cfg-googlesecret" size="36" autocomplete="new-password" placeholder="' + esc(google.secretSet ? t('set — type a new one to replace it') : t('not set')) + '"></label> '
			+ '<button type="button" id="cfg-googlesecret-save">' + esc(t('Save')) + '</button></div>'
			+ '<div class="idreg-admin-row"><label>' + esc(t('Redirect URI (paste this into the Google console)')) + ' <input type="text" size="60" readonly value="' + esc(google.redirectUri) + '" onclick="this.select()"></label></div>'
			+ '<p class="' + (google.ready ? 'ok' : 'warn') + '">' + esc(google.ready
				? t('Ready: the client ID and the secret are set.')
				: t('In the Google Cloud console, create an OAuth client of type "Web application", allow the redirect URI above, and paste the client ID and the secret here. The consent screen only needs the openid, email and profile scopes.')) + '</p>';
		document.getElementById('cfg-google').addEventListener('change', function (e) { save({ googleEnabled: e.target.checked }); });
		document.getElementById('cfg-googleid').addEventListener('change', function (e) { save({ googleClientId: e.target.value.trim() }); });
		document.getElementById('cfg-googlesecret-save').addEventListener('click', function () {
			var field = document.getElementById('cfg-googlesecret');
			saveSecret(field.value.trim());
			field.value = '';
		});
	}

	function chosenGroups() {
		return String(config.defaultGroups || '').split(',').map(function (g) { return g.trim(); }).filter(Boolean);
	}

	function statusLabel(status) {
		if (status === 'active') { return t('active'); }
		if (status === 'awaiting_approval') { return t('waiting for approval'); }
		return t('e-mail not confirmed');
	}

	function renderReader() {
		var box = document.getElementById('idreg-reader');
		if (!box) { return; }
		var ins = reader.install || { state: 'idle' };
		var busy = ins.state === 'queued' || ins.state === 'running';
		var tess = ocr.tesseract || ocr;
		var lines = [];
		if (reader.installed) {
			lines.push(['ok', t('The reader and the face models are installed in the app\'s own environment: {p}', { p: reader.python })]);
		} else if (ocr.rapidocr) {
			lines.push(['ok', t('Documents are read with RapidOCR (neural text recognition), Python: {p}', { p: ocr.python })]);
		} else if (tess.version) {
			lines.push(['warn', t('Documents are read with Tesseract {v}, which reads photographed cards poorly. Install the neural reader below.', { v: tess.version })]);
		} else {
			lines.push(['error', t('No text recognition is installed, so documents cannot be read. Install the reader below.')]);
		}
		lines.push([faces.available ? 'ok' : 'warn', faces.available ? t('Face matching ready ({p})', { p: faces.root }) : t('Face matching is not available: the face models are missing. Install the reader below and they come with it.')]);
		if (!reader.installed && !reader.canInstall) { lines.push(['error', reader.reason]); }
		var note = '';
		if (ins.state === 'queued') { note = t('Waiting for the background job to start (usually within five minutes) …'); }
		else if (ins.state === 'running') { note = t('Installing: {step} …', { step: ins.step }); }
		else if (ins.state === 'failed') { note = t('The installation failed: {error}', { error: ins.error }); }
		else if (ins.state === 'done' && reader.installed) { note = t('Installed.'); }
		box.innerHTML = ''
			+ '<h3>' + esc(t('Reader and face models')) + '</h3>'
			+ '<p class="muted">' + esc(t('The card is read and the faces are compared by a small Python program (RapidOCR and InsightFace\'s models on ONNX Runtime) that the app installs by itself into the data directory — about 450 MB, once. Nothing else on the server is touched.')) + '</p>'
			+ lines.map(function (l) { return '<p class="idreg-' + l[0] + '">' + esc(l[1]) + '</p>'; }).join('')
			+ (note ? '<p class="' + (ins.state === 'failed' ? 'idreg-error' : 'muted') + '">' + esc(note) + '</p>' : '')
			+ ((busy || ins.state === 'failed') && ins.log ? '<pre class="idreg-log">' + esc(ins.log) + '</pre>' : '')
			+ '<div class="idreg-admin-row">'
			+ (!reader.installed && reader.canInstall && !busy ? '<button id="reader-install">' + esc(ins.state === 'failed' ? t('Try again') : t('Install the reader')) + '</button> ' : '')
			+ (reader.installed && !busy ? '<button id="reader-remove">' + esc(t('Remove and install again')) + '</button>' : '')
			+ '</div>';
		var b = document.getElementById('reader-install');
		if (b) { b.addEventListener('click', function () { b.disabled = true; call('POST', '/api/admin/reader').then(function (d) { if (d.error) { alert(d.error); } applyReader(d); }); }); }
		var r = document.getElementById('reader-remove');
		if (r) { r.addEventListener('click', function () { if (window.confirm(t('Remove the reader and install it again?'))) { call('DELETE', '/api/admin/reader').then(applyReader); } }); }
		if (busy && !readerTimer) {
			readerTimer = setInterval(function () { call('GET', '/api/admin/reader').then(applyReader); }, 4000);
		} else if (!busy && readerTimer) {
			clearInterval(readerTimer); readerTimer = null;
			var selfie = document.getElementById('cfg-selfie');
			if (selfie) { selfie.disabled = !faces.available; }
		}
	}
	function applyReader(d) {
		if (!d || !d.reader) { return; }
		reader = d.reader; ocr = d.ocr || ocr; faces = d.faces || faces;
		renderReader();
	}

	function render() {

		var rows = registrations.map(function (r) {
			return '<tr>'
				+ '<td>' + esc(r.name) + '<br><span class="muted">' + esc(r.uid) + '</span></td>'
				+ '<td>' + esc(r.email) + '<br><span class="muted">' + esc(r.phone) + '</span></td>'
				+ '<td><span class="pill ' + esc(r.status) + '">' + esc(statusLabel(r.status)) + '</span></td>'
				+ '<td>' + esc(new Date(r.created_at * 1000).toLocaleString()) + '</td>'
				+ '<td>'
				+ (r.status === 'awaiting_approval' ? '<button class="approve" data-id="' + r.id + '">' + esc(t('Approve')) + '</button> ' : '')
				+ '<button class="remove" data-id="' + r.id + '">' + esc(t('Delete')) + '</button>'
				+ '</td></tr>';
		}).join('');

		root.innerHTML = ''
			+ '<h2>IDRegister</h2>'
			+ '<p class="muted">' + esc(t('Visitors register themselves with a photo of their identity card and a verified e-mail address. The picture and the personal number are never stored.')) + '</p>'
			+ '<div id="idreg-reader"></div>'
			+ '<div class="idreg-admin-row"><label><input type="checkbox" id="cfg-open"' + (config.registrationOpen ? ' checked' : '') + '> ' + esc(t('Allow registration with an identity card')) + '</label></div>'
			+ '<div class="idreg-admin-row"><label><input type="checkbox" id="cfg-approval"' + (config.requireApproval ? ' checked' : '') + '> ' + esc(t('An administrator has to approve every new account')) + '</label></div>'
			+ '<div class="idreg-admin-row"><label><input type="checkbox" id="cfg-onecard"' + (config.oneAccountPerCard ? ' checked' : '') + '> ' + esc(t('One account per identity card')) + '</label></div>'
			+ '<div class="idreg-admin-row"><label><input type="checkbox" id="cfg-mobileonly"' + (config.mobileOnly ? ' checked' : '') + '> ' + esc(t('Registration only from a phone or a tablet (a computer gets a QR code)')) + '</label></div>'
			+ '<div class="idreg-admin-row"><label><input type="checkbox" id="cfg-express"' + (config.expressMode ? ' checked' : '') + '> ' + esc(t('Express: the account is created right after the document and the selfie, with a random user name and password kept by the browser; e-mail, phone and nickname are added in the profile')) + '</label></div>'
			+ '<h3>' + esc(t('Accepted documents')) + '</h3>'
			+ '<div class="idreg-admin-row"><label><input type="checkbox" id="cfg-idcard"' + (config.acceptIdCard ? ' checked' : '') + '> ' + esc(t('Identity card')) + '</label>'
			+ '<label><input type="checkbox" id="cfg-licence"' + (config.acceptDrivingLicence ? ' checked' : '') + '> ' + esc(t('Driving licence')) + '</label></div>'
			+ '<h3>' + esc(t('Selfie')) + '</h3>'
			+ '<div class="idreg-admin-row"><label>' + esc(t('Android app (suggested when the scan in the browser struggles; empty = never)')) + ' <input type="url" id="cfg-appurl" size="48" value="' + esc(config.androidAppUrl || '') + '"></label></div>'
			+ '<div class="idreg-admin-row"><label><input type="checkbox" id="cfg-valid"' + (config.requireValidDocument ? ' checked' : '') + '> ' + esc(t('Require a valid document: an expired card is refused; when the expiry date cannot be read, an administrator reviews the registration')) + '</label></div>'
			+ '<div class="idreg-admin-row"><label><input type="checkbox" id="cfg-liveness"' + (config.requireSelfieLiveness ? ' checked' : '') + '> ' + esc(t('Ask for a small head turn during the selfie (a photo held in front of the camera cannot do it); without it, an administrator reviews the registration')) + '</label></div>'
			+ '<div class="idreg-admin-row"><label><input type="checkbox" id="cfg-physical"' + (config.requirePhysical ? ' checked' : '') + '> ' + esc(t('Require the physical document: black-and-white copies and pictures on a screen are refused; when nothing proves the card real, the visitor is asked to tilt it, and if still unsure an administrator reviews the registration')) + '</label></div>'
			+ '<div class="idreg-admin-row"><label><input type="checkbox" id="cfg-selfie"' + (config.requireSelfie ? ' checked' : '') + (faces.available ? '' : ' disabled') + '> ' + esc(t('Ask for a selfie and compare it with the photo on the document')) + '</label></div>'
			+ '<div class="idreg-admin-row"><label>' + esc(t('Sure match up to (distance)')) + ' <input type="number" id="cfg-match" min="0.5" max="2" step="0.05" value="' + esc(config.selfieMatchDistance) + '"></label>'
			+ '<label>' + esc(t('Ask an administrator up to (distance)')) + ' <input type="number" id="cfg-review" min="0.5" max="2" step="0.05" value="' + esc(config.selfieReviewDistance) + '"></label></div>'
			+ '<p class="muted">' + esc(t('Measured here: the same person 0.43–1.13, different people 1.27–1.49')) + '</p>'
			+ '<h3>' + esc(t('What a new account gets')) + '</h3>'
			+ '<div class="idreg-admin-row"><label>' + esc(t('Groups the new account joins')) + ' <select id="cfg-groups" multiple size="' + Math.min(6, Math.max(3, groups.length)) + '">'
			+ groups.map(function (g) { return '<option value="' + esc(g.id) + '"' + (chosenGroups().indexOf(g.id) >= 0 ? ' selected' : '') + '>' + esc(g.name) + '</option>'; }).join('')
			+ '</select></label><span class="muted">' + esc(t('Hold Ctrl to pick more than one')) + '</span></div>'
			+ '<div class="idreg-admin-row"><label>' + esc(t('Quota for new users')) + ' <input type="text" id="cfg-quota" placeholder="5 GB" list="idreg-quotas" value="' + esc(config.quota) + '"></label>'
			+ '<datalist id="idreg-quotas"><option value="1 GB"><option value="5 GB"><option value="10 GB"><option value="50 GB"><option value="unlimited"></datalist>'
			+ '<span class="muted">' + esc(t('Empty = the default of the instance')) + '</span></div>'
			+ '<h3>' + esc(t('Who may register')) + '</h3>'
			+ '<div class="idreg-admin-row"><label><input type="checkbox" id="cfg-validcnp"' + (config.requireValidCnp ? ' checked' : '') + '> ' + esc(t('The card must have a readable personal number')) + '</label></div>'
			+ '<div class="idreg-admin-row"><label><input type="checkbox" id="cfg-surename"' + (config.requireConfirmedName ? ' checked' : '') + '> ' + esc(t('The name must be confirmed twice on the card (printed and machine readable zone)')) + '</label></div>'
			+ '<div class="idreg-admin-row"><label>' + esc(t('Minimum age (0 = no limit)')) + ' <input type="number" id="cfg-minage" min="0" max="120" value="' + esc(config.minAge) + '"></label><span class="muted">' + esc(t('Read from the personal number on the card')) + '</span></div>'
			+ '<div class="idreg-admin-row"><label><input type="checkbox" id="cfg-phone"' + (config.requirePhone ? ' checked' : '') + '> ' + esc(t('A phone number is required')) + '</label></div>'
			+ '<div class="idreg-admin-row"><label>' + esc(t('Shortest password')) + ' <input type="number" id="cfg-minpass" min="8" max="64" value="' + esc(config.minPasswordLength) + '"></label></div>'
			+ '<div class="idreg-admin-row"><label>' + esc(t('Stop after this many accounts (0 = no limit)')) + ' <input type="number" id="cfg-maxacc" min="0" max="100000" value="' + esc(config.maxAccounts) + '"></label></div>'
			+ '<div class="idreg-admin-row"><label>' + esc(t('Allowed e-mail domains (empty = all)')) + ' <input type="text" id="cfg-allowed" value="' + esc(config.allowedDomains) + '"></label></div>'
			+ '<div class="idreg-admin-row"><label>' + esc(t('Blocked e-mail domains')) + ' <input type="text" id="cfg-blocked" value="' + esc(config.blockedDomains) + '"></label></div>'
			+ '<div class="idreg-admin-row"><label>' + esc(t('Delete unconfirmed registrations after (hours)')) + ' <input type="number" id="cfg-expiry" min="1" max="720" value="' + esc(config.expiryHours) + '"></label></div>'
			+ '<div class="idreg-admin-row"><label>' + esc(t('How sure the card reader must be (0–1)')) + ' <input type="number" id="cfg-conf" min="0" max="1" step="0.05" value="' + esc(config.minConfidence) + '"></label></div>'
			+ '<div class="idreg-admin-row"><label>' + esc(t('Link to your privacy policy (shown on the form)')) + ' <input type="text" id="cfg-terms" placeholder="https://…" value="' + esc(config.termsUrl) + '"></label></div>'
			+ '<div class="idreg-admin-row"><label><input type="checkbox" id="cfg-notify"' + (config.notifyAdmins ? ' checked' : '') + '> ' + esc(t('E-mail the administrators about every new account')) + '</label></div>'
			+ '<div class="idreg-admin-row"><a href="' + esc(registerUrl) + '" target="_blank" rel="noopener">' + esc(t('Open the registration page')) + '</a></div>'
			+ '<h3>' + esc(t('Sign in with Google')) + '</h3>'
			+ '<div id="idreg-google"></div>'
			+ '<h3>' + esc(t('Sign in with your phone')) + '</h3>'
			+ '<div class="idreg-admin-row"><label><input type="checkbox" id="cfg-phone-login"' + (config.phoneLoginEnabled ? ' checked' : '') + '> '
			+ esc(t('Let a paired phone sign an account in')) + '</label></div>'
			+ '<p class="muted">' + esc(t('The account pairs the registration app with itself in its personal settings, with its password and a code on the screen. Afterwards the sign-in page offers "Sign in with your phone": it shows a code and two digits, the phone reads the code, shows who is asking and from where, and signs the answer with a key that never leaves it. A second factor, if the account has one, is still asked afterwards.')) + '</p>'
			+ '<div class="idreg-admin-row"><label>' + esc(t('Address the phone talks to')) + ' <input type="text" size="60" readonly value="' + esc(phoneServer) + '" onclick="this.select()"></label></div>'
			+ '<h3>' + esc(t('Registrations')) + '</h3>'
			+ (registrations.length
				? '<table><thead><tr><th>' + esc(t('Person')) + '</th><th>' + esc(t('Contact')) + '</th><th>' + esc(t('Status')) + '</th><th>' + esc(t('Started')) + '</th><th></th></tr></thead><tbody>' + rows + '</tbody></table>'
				: '<p class="muted">' + esc(t('Nobody has registered yet.')) + '</p>');

		renderReader();
		renderGoogle();
		document.getElementById('cfg-open').addEventListener('change', function (e) { save({ registrationOpen: e.target.checked }); });
		document.getElementById('cfg-approval').addEventListener('change', function (e) { save({ requireApproval: e.target.checked }); });
		document.getElementById('cfg-onecard').addEventListener('change', function (e) { save({ oneAccountPerCard: e.target.checked }); });
		document.getElementById('cfg-validcnp').addEventListener('change', function (e) { save({ requireValidCnp: e.target.checked }); });
		document.getElementById('cfg-surename').addEventListener('change', function (e) { save({ requireConfirmedName: e.target.checked }); });
		document.getElementById('cfg-phone').addEventListener('change', function (e) { save({ requirePhone: e.target.checked }); });
		document.getElementById('cfg-notify').addEventListener('change', function (e) { save({ notifyAdmins: e.target.checked }); });
		document.getElementById('cfg-mobileonly').addEventListener('change', function (e) { save({ mobileOnly: e.target.checked }); });
		document.getElementById('cfg-express').addEventListener('change', function (e) { save({ expressMode: e.target.checked }); });
		document.getElementById('cfg-phone-login').addEventListener('change', function (e) { save({ phoneLoginEnabled: e.target.checked }); });
		document.getElementById('cfg-idcard').addEventListener('change', function (e) { save({ acceptIdCard: e.target.checked }); });
		document.getElementById('cfg-licence').addEventListener('change', function (e) { save({ acceptDrivingLicence: e.target.checked }); });
		document.getElementById('cfg-selfie').addEventListener('change', function (e) { save({ requireSelfie: e.target.checked }); });
		document.getElementById('cfg-physical').addEventListener('change', function (e) { save({ requirePhysical: e.target.checked }); });
		document.getElementById('cfg-liveness').addEventListener('change', function (e) { save({ requireSelfieLiveness: e.target.checked }); });
		document.getElementById('cfg-valid').addEventListener('change', function (e) { save({ requireValidDocument: e.target.checked }); });
		document.getElementById('cfg-appurl').addEventListener('change', function (e) { save({ androidAppUrl: e.target.value.trim() }); });
		document.getElementById('cfg-groups').addEventListener('change', function (e) {
			var picked = Array.prototype.filter.call(e.target.options, function (o) { return o.selected; }).map(function (o) { return o.value; });
			save({ defaultGroups: picked.join(',') });
		});
		['quota', 'allowed', 'blocked', 'expiry', 'conf', 'minage', 'minpass', 'maxacc', 'terms', 'match', 'review'].forEach(function (id) {
			var map = {
				quota: 'quota', allowed: 'allowedDomains', blocked: 'blockedDomains', expiry: 'expiryHours',
				conf: 'minConfidence', minage: 'minAge', minpass: 'minPasswordLength', maxacc: 'maxAccounts', terms: 'termsUrl',
				match: 'selfieMatchDistance', review: 'selfieReviewDistance',
			};
			document.getElementById('cfg-' + id).addEventListener('change', function (e) {
				var patch = {}; patch[map[id]] = e.target.value; save(patch);
			});
		});
		Array.prototype.forEach.call(root.querySelectorAll('button.approve'), function (b) {
			b.addEventListener('click', function () {
				call('POST', '/api/admin/registrations/' + b.dataset.id + '/approve').then(load);
			});
		});
		Array.prototype.forEach.call(root.querySelectorAll('button.remove'), function (b) {
			b.addEventListener('click', function () {
				if (!window.confirm(t('Delete this registration and its account?'))) { return; }
				call('DELETE', '/api/admin/registrations/' + b.dataset.id).then(load);
			});
		});
	}

	function load() {
		call('GET', '/api/admin/registrations').then(function (data) {
			registrations = (data && data.registrations) || [];
			render();
		}).catch(render);
	}

	load();
})();
