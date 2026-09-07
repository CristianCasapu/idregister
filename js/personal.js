/* Tells a user which of their details come from their identity card */
(function () {
	'use strict';
	var root = document.getElementById('idregister-personal');
	if (!root) { return; }
	var t = function (text) {
		return (typeof OC !== 'undefined' && OC.L10N) ? OC.L10N.translate('idregister', text) : text;
	};
	var locked = null;
	try {
		var el = document.querySelector('#initial-state-idregister-locked');
		locked = el ? JSON.parse(atob(el.value)) : null;
	} catch (e) { locked = null; }
	if (!locked) { return; }

	var esc = function (s) {
		return String(s || '').replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	};

	root.innerHTML = '<div class="section">'
		+ '<h2>' + esc(t('Details from your identity card')) + '</h2>'
		+ '<p class="muted">' + esc(t('You registered with your identity card, so these details are fixed. Ask an administrator if something is wrong.')) + '</p>'
		+ '<ul class="locked-list">'
		+ '<li><strong>' + esc(t('Name')) + ':</strong> ' + esc(locked.name) + '</li>'
		+ '<li><strong>' + esc(t('E-mail address')) + ':</strong> ' + esc(locked.email) + '</li>'
		+ (locked.phone ? '<li><strong>' + esc(t('Phone number')) + ':</strong> ' + esc(locked.phone) + '</li>' : '')
		+ '</ul></div>';
})();
