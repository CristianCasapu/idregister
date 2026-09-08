/* Administration of Sign up with ID */
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
	var ocr = initial('ocr', { ok: false, version: '', languages: [], missing: [] });
	var faces = initial('faces', { available: false, python: '', root: '' });
	var groups = initial('groups', []);
	var registerUrl = initial('registerUrl', '');
	var registrations = [];

	function esc(s) {
		return String(s === undefined || s === null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function save(patch) {
		Object.assign(config, patch);
		call('PUT', '/api/admin/config', { config: patch }).then(function (data) {
			if (data && data.config) { config = data.config; }
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

	function render() {
		var tess = ocr.tesseract || ocr;
		var ocrLine = ocr.rapidocr
			? t('Documents are read with RapidOCR (neural text recognition), Python: {p}', { p: ocr.python })
			: (tess.version
				? t('Documents are read with Tesseract {v}, which reads photographed cards poorly. Install the neural reader: occ idregister:install-ocr', { v: tess.version })
				: t('No text recognition is installed: occ idregister:install-ocr (RapidOCR, recommended) or sudo apt install tesseract-ocr tesseract-ocr-ron'));

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
			+ '<h2>' + esc(t('Sign up with ID')) + '</h2>'
			+ '<p class="muted">' + esc(t('Visitors register themselves with a photo of their identity card and a verified e-mail address. The picture and the personal number are never stored.')) + '</p>'
			+ '<p class="muted">' + esc(ocrLine) + '</p>'
			+ '<div class="idreg-admin-row"><label><input type="checkbox" id="cfg-open"' + (config.registrationOpen ? ' checked' : '') + '> ' + esc(t('Allow registration with an identity card')) + '</label></div>'
			+ '<div class="idreg-admin-row"><label><input type="checkbox" id="cfg-approval"' + (config.requireApproval ? ' checked' : '') + '> ' + esc(t('An administrator has to approve every new account')) + '</label></div>'
			+ '<div class="idreg-admin-row"><label><input type="checkbox" id="cfg-onecard"' + (config.oneAccountPerCard ? ' checked' : '') + '> ' + esc(t('One account per identity card')) + '</label></div>'
			+ '<div class="idreg-admin-row"><label><input type="checkbox" id="cfg-mobileonly"' + (config.mobileOnly ? ' checked' : '') + '> ' + esc(t('Registration only from a phone or a tablet (a computer gets a QR code)')) + '</label></div>'
			+ '<h3>' + esc(t('Accepted documents')) + '</h3>'
			+ '<div class="idreg-admin-row"><label><input type="checkbox" id="cfg-idcard"' + (config.acceptIdCard ? ' checked' : '') + '> ' + esc(t('Identity card')) + '</label>'
			+ '<label><input type="checkbox" id="cfg-licence"' + (config.acceptDrivingLicence ? ' checked' : '') + '> ' + esc(t('Driving licence')) + '</label></div>'
			+ '<h3>' + esc(t('Selfie')) + '</h3>'
			+ '<p class="muted">' + esc(faces.available ? t('Face matching ready') : t('Face matching is not available: install InsightFace (the Recognize app sets it up).')) + '</p>'
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
			+ '<h3>' + esc(t('Registrations')) + '</h3>'
			+ (registrations.length
				? '<table><thead><tr><th>' + esc(t('Person')) + '</th><th>' + esc(t('Contact')) + '</th><th>' + esc(t('Status')) + '</th><th>' + esc(t('Started')) + '</th><th></th></tr></thead><tbody>' + rows + '</tbody></table>'
				: '<p class="muted">' + esc(t('Nobody has registered yet.')) + '</p>');

		document.getElementById('cfg-open').addEventListener('change', function (e) { save({ registrationOpen: e.target.checked }); });
		document.getElementById('cfg-approval').addEventListener('change', function (e) { save({ requireApproval: e.target.checked }); });
		document.getElementById('cfg-onecard').addEventListener('change', function (e) { save({ oneAccountPerCard: e.target.checked }); });
		document.getElementById('cfg-validcnp').addEventListener('change', function (e) { save({ requireValidCnp: e.target.checked }); });
		document.getElementById('cfg-surename').addEventListener('change', function (e) { save({ requireConfirmedName: e.target.checked }); });
		document.getElementById('cfg-phone').addEventListener('change', function (e) { save({ requirePhone: e.target.checked }); });
		document.getElementById('cfg-notify').addEventListener('change', function (e) { save({ notifyAdmins: e.target.checked }); });
		document.getElementById('cfg-mobileonly').addEventListener('change', function (e) { save({ mobileOnly: e.target.checked }); });
		document.getElementById('cfg-idcard').addEventListener('change', function (e) { save({ acceptIdCard: e.target.checked }); });
		document.getElementById('cfg-licence').addEventListener('change', function (e) { save({ acceptDrivingLicence: e.target.checked }); });
		document.getElementById('cfg-selfie').addEventListener('change', function (e) { save({ requireSelfie: e.target.checked }); });
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
