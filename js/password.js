/* Shared password strength meter: a bar, a word and a checklist. */
window.idregPassword = (function () {
	'use strict';

	var t = function (text, vars) {
		return (typeof OC !== 'undefined' && OC.L10N) ? OC.L10N.translate('idregister', text, vars) : text;
	};

	/** @return {{score:number, label:string, ok:boolean, rules:object}} */
	function judge(password, minLength) {
		var rules = {
			length: password.length >= minLength,
			case: /[a-zà-ÿ]/.test(password) && /[A-ZÀ-Þ]/.test(password),
			digit: /\d/.test(password),
			symbol: /[^\w\s]/.test(password),
		};
		var score = 0;
		if (rules.length) { score += 1; }
		if (password.length >= minLength + 4) { score += 1; }
		if (rules.case) { score += 1; }
		if (rules.digit) { score += 1; }
		if (rules.symbol) { score += 1; }
		// A password that is little more than a well-known word or sequence is weak whatever its
		// length says; one that merely contains such a word inside a long mixed password is not.
		var stripped = password.replace(/12345|abcde|qwerty|parola|password/gi, '');
		if (/^(.)\1+$/.test(password) || stripped.length < 6) { score = Math.min(score, 1); }

		var labels = [
			t('Too short'), t('Weak'), t('Fair'), t('Good'), t('Strong'), t('Very strong'),
		];
		return {
			score: score,
			label: rules.length ? labels[Math.max(1, score)] : labels[0],
			ok: rules.length && score >= 3,
			rules: rules,
		};
	}

	/**
	 * Wire an input to the bar, the text and the list, and call back with the verdict.
	 */
	function attach(opts) {
		var input = document.getElementById(opts.input);
		var repeat = opts.repeat ? document.getElementById(opts.repeat) : null;
		var bar = document.getElementById(opts.bar);
		var hint = document.getElementById(opts.hint);
		var rules = document.getElementById(opts.rules);
		var eye = opts.eye ? document.getElementById(opts.eye) : null;
		var minLength = opts.minLength || 10;
		if (!input) { return function () { return false; }; }

		if (rules) {
			var first = rules.querySelector('[data-rule="length"]');
			if (first) { first.textContent = t('At least {n} characters', { n: minLength }); }
		}

		function update() {
			var verdict = judge(input.value, minLength);
			if (bar) {
				bar.style.width = Math.round((verdict.score / 5) * 100) + '%';
				bar.className = 'level-' + verdict.score;
			}
			if (hint) {
				var same = !repeat || repeat.value === '' || repeat.value === input.value;
				hint.textContent = !same ? t('The two passwords are different.') : (input.value ? verdict.label : '');
				hint.className = 'idreg-sub' + (!same ? ' warn' : '');
			}
			if (rules) {
				Array.prototype.forEach.call(rules.children, function (li) {
					li.classList.toggle('met', !!verdict.rules[li.dataset.rule]);
				});
			}
			var acceptable = verdict.ok && (!repeat || repeat.value === input.value);
			if (opts.onChange) { opts.onChange(acceptable, verdict); }
			return acceptable;
		}

		input.addEventListener('input', update);
		if (repeat) { repeat.addEventListener('input', update); }
		if (eye) {
			eye.addEventListener('click', function () {
				var show = input.type === 'password';
				input.type = show ? 'text' : 'password';
				if (repeat) { repeat.type = input.type; }
				eye.classList.toggle('on', show);
			});
		}
		update();
		return update;
	}

	/**
	 * A random password that meets every rule: letters of both cases, digits and symbols,
	 * without the characters that are easy to confuse (l/1/I, O/0).
	 */
	function generate(minLength) {
		var lower = 'abcdefghijkmnpqrstuvwxyz', upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ', digits = '23456789', symbols = '!@#$%&*+-=?';
		var all = lower + upper + digits + symbols;
		var length = Math.max((minLength || 10) + 4, 14);
		var pick = function (set) {
			var buf = new Uint32Array(1);
			window.crypto.getRandomValues(buf);
			return set.charAt(buf[0] % set.length);
		};
		var chars = [pick(lower), pick(upper), pick(digits), pick(symbols)];
		while (chars.length < length) { chars.push(pick(all)); }
		// shuffle (Fisher–Yates) so the guaranteed characters are not always first
		for (var i = chars.length - 1; i > 0; i--) {
			var buf = new Uint32Array(1);
			window.crypto.getRandomValues(buf);
			var j = buf[0] % (i + 1);
			var tmp = chars[i]; chars[i] = chars[j]; chars[j] = tmp;
		}
		return chars.join('');
	}

	/** Put a generated password into both fields, show it, and let the meter judge it. */
	function fill(opts) {
		var input = document.getElementById(opts.input);
		var repeat = opts.repeat ? document.getElementById(opts.repeat) : null;
		var eye = opts.eye ? document.getElementById(opts.eye) : null;
		if (!input) { return; }
		var password = generate(opts.minLength);
		input.value = password;
		if (repeat) { repeat.value = password; }
		input.type = 'text';
		if (repeat) { repeat.type = 'text'; }
		if (eye) { eye.classList.add('on'); }
		input.dispatchEvent(new Event('input', { bubbles: true }));
	}

	return { judge: judge, attach: attach, generate: generate, fill: fill };
})();
