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
		// a single repeated character or an obvious sequence is not a password
		if (/^(.)\1+$/.test(password) || /12345|abcde|qwerty|parola|password/i.test(password)) { score = Math.min(score, 1); }

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

	return { judge: judge, attach: attach };
})();
