/* Administration of the ID card registration */
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

	function statusLabel(status) {
		if (status === 'active') { return t('active'); }
		if (status === 'awaiting_approval') { return t('waiting for approval'); }
		return t('e-mail not confirmed');
	}

	function render() {
		var ocrLine = ocr.version
			? (ocr.missing.length
				? t('Tesseract {v} is installed, missing language packs: {m}', { v: ocr.version, m: ocr.missing.join(' ') })
				: t('Tesseract {v}, languages: {l}', { v: ocr.version, l: ocr.languages.join(', ') }))
			: t('Tesseract is not installed: sudo apt install tesseract-ocr tesseract-ocr-ron');

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
			+ '<h2>' + esc(t('ID card registration')) + '</h2>'
			+ '<p class="muted">' + esc(t('Visitors register themselves with a photo of their identity card and a verified e-mail address. The picture and the personal number are never stored.')) + '</p>'
			+ '<p class="muted">' + esc(ocrLine) + '</p>'
			+ '<div class="idreg-admin-row"><label><input type="checkbox" id="cfg-enabled"' + (config.enabled ? ' checked' : '') + '> ' + esc(t('Allow registration with an identity card')) + '</label></div>'
			+ '<div class="idreg-admin-row"><label><input type="checkbox" id="cfg-approval"' + (config.requireApproval ? ' checked' : '') + '> ' + esc(t('An administrator has to approve every new account')) + '</label></div>'
			+ '<div class="idreg-admin-row"><label><input type="checkbox" id="cfg-onecard"' + (config.oneAccountPerCard ? ' checked' : '') + '> ' + esc(t('One account per identity card')) + '</label></div>'
			+ '<div class="idreg-admin-row"><label>' + esc(t('Add new users to the group')) + ' <select id="cfg-group"><option value="">' + esc(t('(none)')) + '</option>'
			+ groups.map(function (g) { return '<option value="' + esc(g.id) + '"' + (config.defaultGroup === g.id ? ' selected' : '') + '>' + esc(g.name) + '</option>'; }).join('')
			+ '</select></label></div>'
			+ '<div class="idreg-admin-row"><label>' + esc(t('Quota for new users')) + ' <input type="text" id="cfg-quota" placeholder="5 GB" value="' + esc(config.quota) + '"></label></div>'
			+ '<div class="idreg-admin-row"><label>' + esc(t('Allowed e-mail domains (empty = all)')) + ' <input type="text" id="cfg-allowed" value="' + esc(config.allowedDomains) + '"></label></div>'
			+ '<div class="idreg-admin-row"><label>' + esc(t('Blocked e-mail domains')) + ' <input type="text" id="cfg-blocked" value="' + esc(config.blockedDomains) + '"></label></div>'
			+ '<div class="idreg-admin-row"><label>' + esc(t('Delete unconfirmed registrations after (hours)')) + ' <input type="number" id="cfg-expiry" min="1" max="720" value="' + esc(config.expiryHours) + '"></label></div>'
			+ '<div class="idreg-admin-row"><label>' + esc(t('How sure the card reader must be (0–1)')) + ' <input type="number" id="cfg-conf" min="0" max="1" step="0.05" value="' + esc(config.minConfidence) + '"></label></div>'
			+ '<div class="idreg-admin-row"><a href="' + esc(registerUrl) + '" target="_blank" rel="noopener">' + esc(t('Open the registration page')) + '</a></div>'
			+ '<h3>' + esc(t('Registrations')) + '</h3>'
			+ (registrations.length
				? '<table><thead><tr><th>' + esc(t('Person')) + '</th><th>' + esc(t('Contact')) + '</th><th>' + esc(t('Status')) + '</th><th>' + esc(t('Started')) + '</th><th></th></tr></thead><tbody>' + rows + '</tbody></table>'
				: '<p class="muted">' + esc(t('Nobody has registered yet.')) + '</p>');

		document.getElementById('cfg-enabled').addEventListener('change', function (e) { save({ enabled: e.target.checked }); });
		document.getElementById('cfg-approval').addEventListener('change', function (e) { save({ requireApproval: e.target.checked }); });
		document.getElementById('cfg-onecard').addEventListener('change', function (e) { save({ oneAccountPerCard: e.target.checked }); });
		document.getElementById('cfg-group').addEventListener('change', function (e) { save({ defaultGroup: e.target.value }); });
		['quota', 'allowed', 'blocked', 'expiry', 'conf'].forEach(function (id) {
			var map = { quota: 'quota', allowed: 'allowedDomains', blocked: 'blockedDomains', expiry: 'expiryHours', conf: 'minConfidence' };
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
