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
		requireSelfie: true, acceptIdCard: true, acceptDrivingLicence: true, express: true,
	});
	var express = !!conditions.express;
	var serverSaysMobile = initial('mobile', false);
	var mobileOnly = initial('mobileOnly', true);
	var handoff = initial('handoff', '');
	var appUrl = initial('appUrl', '');
	// the phone app finished: this browser signs in as the new account (see followHandoff)
	var takeOver = /[?&]take=1/.test(window.location.search) && '' !== handoff;
	var struggles = 0;
	/** Suggested once, when the scan in the browser does not get anywhere: the native app reads better. */
	function suggestApp(why) {
		if (!appUrl) { return; }
		struggles += 1;
		if (struggles < (why === 'camera' ? 1 : 2) || $('idreg-app-hint')) { return; }
		var hint = document.createElement('p');
		hint.className = 'idreg-hint idreg-app-hint';
		hint.id = 'idreg-app-hint';
		var link = document.createElement('a');
		link.href = appUrl;
		link.target = '_blank';
		link.rel = 'noopener';
		link.textContent = t('Install the registration app');
		hint.appendChild(document.createTextNode(t('Does it not work in the browser?') + ' '));
		hint.appendChild(link);
		var cam = $('idreg-cam');
		cam.parentNode.insertBefore(hint, cam.nextSibling);
	}

	var state = { file: null, selfie: null, card: null, scanId: '', token: '', email: '', language: (document.documentElement.lang || 'en') };
	var passwordOk = false;
	var currentStep = 1;

	/* Switching to the mail app for the code often makes the phone reload this page: what was
	 * reached is kept in the browser (no personal number, no pictures) for two hours. */
	var STORE = 'idregister.wizard';
	function saveState() {
		try {
			if (currentStep < 2 || (currentStep > 6 && currentStep !== 8)) { window.localStorage.removeItem(STORE); return; }
			window.localStorage.setItem(STORE, JSON.stringify({
				step: currentStep, scanId: state.scanId, token: state.token, email: state.email, card: state.card ? {
					type: state.card.type, surname: state.card.surname, givenNames: state.card.givenNames, needsSelfie: state.card.needsSelfie,
				} : null, time: Date.now(),
			}));
		} catch (e) { /* private mode: nothing to keep */ }
	}
	function savedState() {
		try {
			var saved = JSON.parse(window.localStorage.getItem(STORE) || 'null');
			if (saved && saved.step >= 2 && (saved.step <= 6 || saved.step === 8) && Date.now() - saved.time < 2 * 3600 * 1000) { return saved; }
		} catch (e) { /* ignore */ }
		return null;
	}

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

	/**
	 * The spinner is shown only while a request of ours is actually running: it is a counter,
	 * so it can never be left turning after an answer came back.
	 */
	var inFlight = 0;
	function busy(on, what) {
		inFlight += on ? 1 : -1;
		if (inFlight < 0) { inFlight = 0; }
		if (on && what) { $('idreg-spinner-text').textContent = what; }
		$('idreg-spinner').hidden = inFlight === 0;
		Array.prototype.forEach.call(root.querySelectorAll('button'), function (b) { b.disabled = inFlight > 0; });
		if (inFlight === 0) {
			$('idreg-scan').disabled = !state.file;
			$('idreg-check-selfie').disabled = !state.selfie;
			$('idreg-finish').disabled = !passwordOk;
		}
	}

	/** One step at a time; the others are removed from the page, not just hidden. */
	var STEPS = express ? (conditions.requireSelfie ? 4 : 3) : 6;
	/** the number shown for a section: express skips the e-mail, code and password sections */
	function shownStep(n) {
		if (!express) { return Math.min(Math.max(n, 1), STEPS); }
		if (n === 8) { return STEPS; }
		return Math.min(Math.max(n, 1), STEPS);
	}
	function step(n) {
		currentStep = n;
		Array.prototype.forEach.call(root.querySelectorAll('.idreg-step'), function (s) {
			s.hidden = Number(s.dataset.step) !== n;
		});
		if (n === 1) { window.setTimeout(startCamera, 0); } else if (typeof stopCamera === 'function') { stopCamera(); }
		if (n === 3) { window.setTimeout(startSelfieCamera, 0); } else if (typeof stopSelfieCamera === 'function') { stopSelfieCamera(); }
		saveState();
		var shown = shownStep(n);
		Array.prototype.forEach.call(root.querySelectorAll('.dot'), function (d) {
			d.hidden = Number(d.dataset.dot) > STEPS;
			d.classList.toggle('on', Number(d.dataset.dot) <= shown);
		});
		root.querySelector('.idreg-stepline').hidden = n === 0 || (n > STEPS && n !== 8);
		$('idreg-stepcount').textContent = t('Step {n} of {total}', { n: shown, total: STEPS });
		message('');
		window.scrollTo({ top: 0, behavior: 'smooth' });
	}

	function post(path, body, isForm) {
		var headers = { requesttoken: (typeof OC !== 'undefined' && OC.requestToken) || '' };
		if (!isForm) { headers['Content-Type'] = 'application/json'; }
		return fetch(url(path), { method: 'POST', headers: headers, body: isForm ? body : JSON.stringify(body) })
			.then(function (r) {
				if (r.status === 429) { return { ok: false, message: t('Too many attempts from this connection. Please wait a while and try again.') }; }
				return r.json();
			});
	}

	/**
	 * A phone or a tablet? The server decides from the User-Agent and refuses the document scan
	 * from anything else, so the page follows the same verdict: a computer never gets the form,
	 * whatever its screen or touch support says.
	 */
	function isHandheld() {
		return serverSaysMobile;
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

	/** The hidden sign-in form, ready to post: session token, where to land, the time zone. */
	function prepareLogin() {
		// the browser's password manager is asked directly where it exists (Chrome, Android)
		try {
			if (window.PasswordCredential && navigator.credentials && created) {
				navigator.credentials.store(new window.PasswordCredential({ id: created.uid, password: created.password, name: created.name || created.uid }));
			}
		} catch (e) { /* not supported */ }
		try { window.localStorage.removeItem(STORE); window.sessionStorage.removeItem('idregister.created'); } catch (e) { /* ignore */ }
		// Nextcloud sends this page with "Referrer-Policy: no-referrer", which makes the browser post
		// the sign-in with "Origin: null" — and the sign-in refuses that. Same-origin is enough here.
		if (!document.querySelector('meta[name="referrer"]')) {
			var meta = document.createElement('meta');
			meta.name = 'referrer';
			meta.content = 'same-origin';
			document.head.appendChild(meta);
		}
		$('idreg-login-form').action = (typeof OC !== 'undefined' && OC.generateUrl) ? OC.generateUrl('/login') : '/index.php/login';
		// the sign-in needs the session's request token, like the login form itself
		$('idreg-login-token').value = (typeof OC !== 'undefined' && OC.requestToken) || (document.head.getAttribute('data-requesttoken') || '');
		$('idreg-login-redirect').value = (typeof OC !== 'undefined' && OC.generateUrl) ? OC.generateUrl('/settings/user') : '/index.php/settings/user';
		try {
			$('idreg-login-tz').value = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
			$('idreg-login-tzo').value = String(-new Date().getTimezoneOffset() / 60);
		} catch (e) { /* ignore */ }
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
					if (data.login && data.login.uid) {
						// the phone is done: this computer signs in as the new account and lands on the profile
						message(t('The account of {name} is ready. Signing you in …', { name: data.name || '' }), 'ok');
						created = { uid: data.login.uid, password: data.login.password, name: data.name || '' };
						$('idreg-login-user').value = data.login.uid;
						$('idreg-login-password').value = data.login.password;
						window.setTimeout(function () {
							prepareLogin();
							$('idreg-login-form').submit();
						}, 600);
					} else {
						message(t('The account of {name} is ready. You can sign in.', { name: data.name || '' }), 'ok');
					}
				}
				if (data.state === 'expired') { window.clearInterval(timer); }
			}).catch(function () {});
		}, 3000);
	}

	if (takeOver) {
		step(0);
		$('idreg-qr').parentNode.hidden = true;
		$('idreg-qr-url').hidden = true;
		message(t('Finishing your registration …'), null);
		followHandoff(handoff);
		return;
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

	/* ---- step 1: the document, read live with the camera (as NecMat does it) ----
	 * Frames are cropped to the guide and sent one after another; the server reads each one,
	 * adds it to what earlier frames gave, and says what to do (closer, further, hold still).
	 * The scan ends by itself once the personal number came out the same in two frames.
	 * No frame is stored anywhere; only the fields survive, on the server. */
	var cam = {
		video: $('idreg-video'), overlay: $('idreg-overlay'), box: $('idreg-cam'),
		status: $('idreg-cam-status'), stream: null, track: null,
		running: false, first: true, level: 0, boxes: [], lines: 0, sending: false, torch: false,
	};

	function guideRect(w, h) {
		var gw = w * 0.9;
		var gh = gw * 54 / 85.6;
		var left = (w - gw) / 2;
		var top = (h - gh) / 2 - h * 0.06;
		return { left: left, top: top, width: gw, height: gh };
	}

	/** object-fit: cover — the same scaling and centring as the video element does */
	function mapping() {
		var vw = cam.video.clientWidth, vh = cam.video.clientHeight;
		var iw = cam.video.videoWidth || 1, ih = cam.video.videoHeight || 1;
		var sc = Math.max(vw / iw, vh / ih);
		return { sc: sc, dx: (vw - iw * sc) / 2, dy: (vh - ih * sc) / 2, vw: vw, vh: vh, iw: iw, ih: ih };
	}

	/** the guide plus a margin, in pixels of the camera image */
	function cropRect() {
		var m = mapping();
		var g = guideRect(m.vw, m.vh);
		var mx = g.width * 0.08, my = g.height * 0.15;
		var clamp = function (v, lo, hi) { return Math.min(Math.max(v, lo), hi); };
		var l = clamp((g.left - mx - m.dx) / m.sc, 0, m.iw - 2);
		var t = clamp((g.top - my - m.dy) / m.sc, 0, m.ih - 2);
		var r = clamp((g.left + g.width + mx - m.dx) / m.sc, l + 1, m.iw);
		var b = clamp((g.top + g.height + my - m.dy) / m.sc, t + 1, m.ih);
		return { l: l, t: t, w: r - l, h: b - t };
	}

	function drawOverlay() {
		var c = cam.overlay;
		var w = cam.video.clientWidth, h = cam.video.clientHeight;
		if (!w || !h) { return; }
		var ratio = window.devicePixelRatio || 1;
		if (c.width !== Math.round(w * ratio)) { c.width = Math.round(w * ratio); c.height = Math.round(h * ratio); }
		var ctx = c.getContext('2d');
		ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
		ctx.clearRect(0, 0, w, h);
		var g = guideRect(w, h);
		ctx.fillStyle = 'rgba(0,0,0,0.45)';
		ctx.fillRect(0, 0, w, g.top);
		ctx.fillRect(0, g.top + g.height, w, h - g.top - g.height);
		ctx.fillRect(0, g.top, g.left, g.height);
		ctx.fillRect(g.left + g.width, g.top, w - g.left - g.width, g.height);
		ctx.strokeStyle = cam.level === 2 ? '#43a047' : (cam.level === 1 ? '#ffb300' : '#e53935');
		ctx.lineWidth = 4;
		ctx.beginPath();
		if (ctx.roundRect) { ctx.roundRect(g.left, g.top, g.width, g.height, 14); } else { ctx.rect(g.left, g.top, g.width, g.height); }
		ctx.stroke();
		// the text the server found, back from the crop into the view
		var crop = cropRect();
		var m = mapping();
		if (debugScan) {
			ctx.strokeStyle = 'rgba(255,0,255,0.9)';
			ctx.lineWidth = 2;
			ctx.strokeRect(crop.l * m.sc + m.dx, crop.t * m.sc + m.dy, crop.w * m.sc, crop.h * m.sc);
		}
		ctx.strokeStyle = 'rgba(100,181,246,0.9)';
		ctx.lineWidth = 2;
		cam.boxes.forEach(function (b) {
			var x = (crop.l + b.x * crop.w) * m.sc + m.dx;
			var y = (crop.t + b.y * crop.h) * m.sc + m.dy;
			ctx.strokeRect(x, y, b.w * crop.w * m.sc, b.h * crop.h * m.sc);
		});
	}

	function grabFrame() {
		var crop = cropRect();
		var canvas = document.createElement('canvas');
		var scale = Math.min(1, 1400 / crop.w);
		canvas.width = Math.max(2, Math.round(crop.w * scale));
		canvas.height = Math.max(2, Math.round(crop.h * scale));
		canvas.getContext('2d').drawImage(cam.video, crop.l, crop.t, crop.w, crop.h, 0, 0, canvas.width, canvas.height);
		return new Promise(function (resolve) { canvas.toBlob(resolve, 'image/jpeg', 0.85); });
	}

	var debugScan = /[?&]debug=1/.test(window.location.search);
	function setStatus(text, level) {
		cam.status.textContent = text;
		cam.level = level;
		if (debugScan && cam.video.videoWidth) {
			var m = mapping(), c = cropRect();
			cam.status.textContent += ' [' + m.iw + 'x' + m.ih + ' → ' + Math.round(m.vw) + 'x' + Math.round(m.vh) + ' sc ' + m.sc.toFixed(3)
				+ ' crop ' + Math.round(c.l) + ',' + Math.round(c.t) + ' ' + Math.round(c.w) + 'x' + Math.round(c.h) + ']';
		}
	}

	/** the camera box in front of everything while it runs */
	function camLive(box, on) {
		box.classList.toggle('live', on);
		box.classList.toggle('tilt', false);
		var anyLive = document.querySelector('.idreg-cam.live');
		document.documentElement.classList.toggle('idreg-cam-open', !!anyLive);
		if (on) { window.setTimeout(function () { window.dispatchEvent(new Event('resize')); }, 50); }
	}

	function stopCamera() {
		if (!cam) { return; } // the desktop hand-off shows the QR before the camera objects exist
		cam.running = false;
		camLive(cam.box, false);
		if (cam.stream) {
			cam.stream.getTracks().forEach(function (tr) { tr.stop(); });
			cam.stream = null;
			cam.track = null;
		}
	}

	function documentRead(data) {
		state.card = { type: data.type, surname: data.surname, givenNames: data.givenNames, needsSelfie: data.needsSelfie };
		state.scanId = data.scanId;
		$('idreg-given').value = data.givenNames;
		$('idreg-surname').value = data.surname;
		$('idreg-doc-type').textContent = data.type === 'driving_licence'
			? t('Read from a driving licence.')
			: t('Read from an identity card.');
		step(2);
	}

	/** one frame to the server; resolves when the answer is handled */
	function sendFrame(final) {
		if (cam.sending || !cam.video.videoWidth) { return Promise.resolve(); }
		cam.sending = true;
		return grabFrame().then(function (blob) {
			if (!blob || !cam.running) { return; }
			var form = new FormData();
			form.append('frame', blob, 'frame.jpg');
			if (handoff) { form.append('handoff', handoff); }
			if (cam.first) { form.append('start', '1'); cam.first = false; }
			if (final) { form.append('final', '1'); }
			return fetch(url('/api/scan/frame'), { method: 'POST', headers: { requesttoken: (typeof OC !== 'undefined' && OC.requestToken) || '' }, body: form })
				.then(function (r) { return r.json(); })
				.then(function (data) {
					if (!cam.running) { return; }
					if (data.fatal) {
						stopCamera();
						showPhotoFallback(data.message);
						return;
					}
					cam.boxes = data.boxes || [];
					cam.lines = data.lines || 0;
					if (data.status) { setStatus(data.status, data.level || 0); }
					cam.box.classList.toggle('tilt', !!data.tilt);
					if (data.blocked) {
						// a copy or a screen: keep looking, the real card may come next
						$('idreg-use').disabled = true;
						return;
					}
					if (data.frames >= 40 && !data.done) { suggestApp('slow'); }
					$('idreg-use').disabled = !(cam.lines >= 3 || data.frames > 0);
					if (data.done) {
						if (data.ok) {
							stopCamera();
							documentRead(data);
						} else {
							// read, but not good enough (name not confirmed, too young …): say why and keep looking
							message(data.message || t('The identity card could not be read. Try again with more light and the whole card in the frame.'), 'error');
							suggestApp('read');
							cam.first = true;
						}
					} else if (final && data.message) {
						message(data.message, 'error');
					}
				});
		}).catch(function () {
			// a lost frame is not an error: the next one follows
		}).then(function () { cam.sending = false; });
	}

	function frameLoop() {
		if (!cam.running) { return; }
		var started = Date.now();
		sendFrame(false).then(function () {
			drawOverlay();
			var wait = Math.max(0, 350 - (Date.now() - started));
			window.setTimeout(frameLoop, wait);
		});
	}

	function showPhotoFallback(why) {
		suggestApp('camera');
		cam.box.classList.add('off');
		setStatus(why || t('The camera could not be started. Take a picture instead.'), 0);
		$('idreg-photo').hidden = false;
		$('idreg-photo-link').parentNode.hidden = true;
	}

	function startCamera() {
		if (!ocrReady || cam.running) { return; }
		if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
			showPhotoFallback();
			return;
		}
		cam.box.classList.remove('off');
		$('idreg-photo').hidden = true;
		$('idreg-photo-link').parentNode.hidden = false;
		cam.running = true;
		camLive(cam.box, true);
		cam.first = true;
		cam.boxes = [];
		cam.lines = 0;
		$('idreg-use').disabled = true;
		setStatus(t('Starting the camera …'), 0);
		navigator.mediaDevices.getUserMedia({
			audio: false,
			video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1440 } },
		}).then(function (stream) {
			if (!cam.running) { stream.getTracks().forEach(function (tr) { tr.stop(); }); return; }
			cam.stream = stream;
			cam.track = stream.getVideoTracks()[0];
			cam.video.srcObject = stream;
			var caps = cam.track.getCapabilities ? cam.track.getCapabilities() : {};
			$('idreg-torch').hidden = !caps.torch;
			return cam.video.play().catch(function () {});
		}).then(function () {
			if (!cam.running) { return; }
			setStatus(t('Put the document inside the frame'), 0);
			var begin = function () {
				if (!cam.running) { return; }
				drawOverlay();
				frameLoop();
			};
			if (cam.video.videoWidth) { begin(); } else { cam.video.addEventListener('loadedmetadata', begin, { once: true }); }
		}).catch(function (e) {
			console.warn('camera', e);
			cam.running = false;
			showPhotoFallback();
		});
	}

	$('idreg-torch').addEventListener('click', function () {
		if (!cam.track) { return; }
		cam.torch = !cam.torch;
		cam.track.applyConstraints({ advanced: [{ torch: cam.torch }] }).catch(function () {});
		$('idreg-torch').querySelector('span').textContent = cam.torch ? t('Torch: on') : t('Torch');
	});

	$('idreg-use').addEventListener('click', function () {
		if (!cam.running) { return; }
		setStatus(t('Reading the document …'), 1);
		sendFrame(true);
	});

	$('idreg-photo-link').addEventListener('click', function (event) {
		event.preventDefault();
		stopCamera();
		showPhotoFallback(t('Photograph the document'));
	});
	$('idreg-cam-close').addEventListener('click', function () {
		stopCamera();
		showPhotoFallback(t('Photograph the document'));
	});

	window.addEventListener('resize', function () {
		if (cam.running) { drawOverlay(); }
		if (typeof selfie !== 'undefined' && selfie && selfie.running) { drawSelfieOverlay(); }
	});
	window.addEventListener('pagehide', stopCamera);

	/* ---- step 1 (fallback): a picture of the document ---- */
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
			documentRead(data);
		}).catch(function () {
			busy(false);
			message(t('Something went wrong. Please try again.'), 'error');
		});
	});

	/* ---- step 2 ---- */
	$('idreg-again').addEventListener('click', function () { state.scanId = ''; state.card = null; step(1); });
	$('idreg-confirm-card').addEventListener('click', function () {
		if (express && !$('idreg-terms-express').checked) { message(t('Please accept how your data is used.'), 'error'); return; }
		if (state.card && state.card.needsSelfie) { step(3); return; }
		if (express) { createExpress(); return; }
		step(4);
	});

	/* ---- express: the account is created now; the browser keeps the sign-in details ---- */
	var created = null;
	function showCreated(data) {
		created = data;
		$('idreg-new-uid').value = data.uid;
		$('idreg-new-password').value = data.password;
		$('idreg-login-user').value = data.uid;
		$('idreg-login-password').value = data.password;
		var approval = data.status === 'awaiting_approval';
		$('idreg-express-approval').hidden = !approval;
		$('idreg-login-form').hidden = approval;
		try { window.sessionStorage.setItem('idregister.created', JSON.stringify(data)); } catch (e) { /* ignore */ }
		step(8);
	}
	function createExpress() {
		busy(true, t('Creating the account …'));
		post('/api/express', { scanId: state.scanId, handoff: handoff, terms: true }).then(function (data) {
			busy(false);
			if (!data.ok) { message(data.message, 'error'); return; }
			showCreated(data);
		}).catch(function () {
			busy(false);
			message(t('Something went wrong. Please try again.'), 'error');
		});
	}
	Array.prototype.forEach.call(root.querySelectorAll('.idreg-copy'), function (button) {
		button.addEventListener('click', function () {
			var value = $(button.dataset.copy).value;
			var done = function () { button.classList.add('done'); window.setTimeout(function () { button.classList.remove('done'); }, 1500); };
			if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(value).then(done, done); }
			else { $(button.dataset.copy).select(); try { document.execCommand('copy'); } catch (e) { /* ignore */ } done(); }
		});
	});
	$('idreg-login-form').addEventListener('submit', prepareLogin);

	/* ---- step 3: the selfie, with the front camera and a face-shaped guide ---- */
	var selfie = { video: $('idreg-selfie-video'), overlay: $('idreg-selfie-overlay'), box: $('idreg-selfie-cam'), status: $('idreg-selfie-status'), stream: null, running: false, timer: null };

	function ovalRect(w, h) {
		var rw = w * 0.62, rh = rw * 1.3;
		if (rh > h * 0.8) { rh = h * 0.8; rw = rh / 1.3; }
		return { cx: w / 2, cy: h * 0.47, rx: rw / 2, ry: rh / 2 };
	}

	function drawSelfieOverlay() {
		var c = selfie.overlay;
		var w = selfie.video.clientWidth, h = selfie.video.clientHeight;
		if (!w || !h) { return; }
		var ratio = window.devicePixelRatio || 1;
		if (c.width !== Math.round(w * ratio)) { c.width = Math.round(w * ratio); c.height = Math.round(h * ratio); }
		var ctx = c.getContext('2d');
		ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
		ctx.clearRect(0, 0, w, h);
		var o = ovalRect(w, h);
		// darken everything outside the face oval
		ctx.fillStyle = 'rgba(0,0,0,0.5)';
		ctx.beginPath();
		ctx.rect(0, 0, w, h);
		ctx.ellipse(o.cx, o.cy, o.rx, o.ry, 0, 0, Math.PI * 2, true);
		ctx.fill('evenodd');
		ctx.strokeStyle = '#ffffff';
		ctx.lineWidth = 3;
		ctx.setLineDash([10, 8]);
		ctx.beginPath();
		ctx.ellipse(o.cx, o.cy, o.rx, o.ry, 0, 0, Math.PI * 2);
		ctx.stroke();
		ctx.setLineDash([]);
	}

	function stopSelfieCamera() {
		if (!selfie) { return; }
		selfie.running = false;
		camLive(selfie.box, false);
		if (selfie.timer) { window.clearInterval(selfie.timer); selfie.timer = null; }
		if (selfie.stream) {
			selfie.stream.getTracks().forEach(function (tr) { tr.stop(); });
			selfie.stream = null;
		}
	}

	function showSelfieFallback(why) {
		selfie.box.classList.add('off');
		selfie.status.textContent = why || t('The camera could not be started. Take a picture instead.');
		$('idreg-selfie-photo').hidden = false;
		$('idreg-selfie-photo-link').parentNode.hidden = true;
	}

	function startSelfieCamera() {
		if (selfie.running) { return; }
		if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { showSelfieFallback(); return; }
		selfie.box.classList.remove('off');
		$('idreg-selfie-photo').hidden = true;
		$('idreg-selfie-photo-link').parentNode.hidden = false;
		$('idreg-take-selfie').disabled = true;
		selfie.running = true;
		camLive(selfie.box, true);
		selfie.status.textContent = t('Starting the camera …');
		navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: 'user' }, width: { ideal: 1280 }, height: { ideal: 1280 } } })
			.then(function (stream) {
				if (!selfie.running) { stream.getTracks().forEach(function (tr) { tr.stop(); }); return; }
				selfie.stream = stream;
				selfie.video.srcObject = stream;
				return selfie.video.play().catch(function () {});
			}).then(function () {
				if (!selfie.running) { return; }
				selfie.status.textContent = t('Put your face inside the oval');
				$('idreg-take-selfie').disabled = false;
				drawSelfieOverlay();
				selfie.timer = window.setInterval(drawSelfieOverlay, 500);
			}).catch(function (e) {
				console.warn('selfie camera', e);
				selfie.running = false;
				showSelfieFallback();
			});
	}

	/** the picture around the oval (a little wider), as the server compares it with the document */
	function grabSelfie() {
		var v = selfie.video;
		var vw = v.clientWidth, vh = v.clientHeight, iw = v.videoWidth, ih = v.videoHeight;
		var sc = Math.max(vw / iw, vh / ih);
		var dx = (vw - iw * sc) / 2, dy = (vh - ih * sc) / 2;
		var o = ovalRect(vw, vh);
		var l = Math.max(0, (o.cx - o.rx * 1.35 - dx) / sc), tp = Math.max(0, (o.cy - o.ry * 1.25 - dy) / sc);
		var r = Math.min(iw, (o.cx + o.rx * 1.35 - dx) / sc), b = Math.min(ih, (o.cy + o.ry * 1.25 - dy) / sc);
		var canvas = document.createElement('canvas');
		canvas.width = Math.max(2, Math.round(r - l));
		canvas.height = Math.max(2, Math.round(b - tp));
		canvas.getContext('2d').drawImage(v, l, tp, r - l, b - tp, 0, 0, canvas.width, canvas.height);
		return new Promise(function (resolve) { canvas.toBlob(resolve, 'image/jpeg', 0.9); });
	}

	function checkSelfie(blob) {
		busy(true, t('Comparing with the photo on the document …'));
		var form = new FormData();
		form.append('image', blob, 'selfie.jpg');
		form.append('scanId', state.scanId);
		return post('/api/selfie', form, true).then(function (data) {
			busy(false);
			if (!data.ok) { message(data.message, 'error'); return false; }
			if (data.review) {
				message(t('We are not completely sure it is the same person, so an administrator will look at your registration.'), null);
			}
			if (express) { createExpress(); } else { step(4); }
			return true;
		}).catch(function () {
			busy(false);
			message(t('Something went wrong. Please try again.'), 'error');
			return false;
		});
	}

	$('idreg-selfie-close').addEventListener('click', function () {
		stopSelfieCamera();
		showSelfieFallback(t('Take a selfie'));
	});
	$('idreg-selfie-back').addEventListener('click', function () { $('idreg-back-selfie').click(); });

	$('idreg-take-selfie').addEventListener('click', function () {
		if (!selfie.running || !selfie.video.videoWidth) { return; }
		grabSelfie().then(function (blob) {
			if (!blob) { return; }
			selfie.status.textContent = t('Comparing with the photo on the document …');
			return checkSelfie(blob).then(function (ok) {
				if (!ok && selfie.running) { selfie.status.textContent = t('Put your face inside the oval'); }
			});
		});
	});

	$('idreg-selfie-photo-link').addEventListener('click', function (event) {
		event.preventDefault();
		stopSelfieCamera();
		showSelfieFallback(t('Take a selfie'));
	});

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
		checkSelfie(state.selfie);
	});

	/* ---- step 4: the account ---- */
	$('idreg-back-2').addEventListener('click', function () {
		step(state.card && state.card.needsSelfie ? 3 : 2);
	});

	$('idreg-submit').addEventListener('click', function () {
		var email = $('idreg-email').value.trim();
		var phone = $('idreg-phone').value.trim();
		if (!email || email.indexOf('@') < 1) { message(t('This e-mail address is not valid.'), 'error'); return; }
		if ((conditions.requirePhone || phone) && phone.replace(/\D/g, '').length < 9) { message(t('This phone number is not valid.'), 'error'); return; }
		if (!$('idreg-terms').checked) { message(t('Please accept how your data is used.'), 'error'); return; }

		busy(true, t('Sending the code …'));
		post('/api/register', {
			scanId: state.scanId,
			handoff: handoff,
			email: email,
			phone: phone,
			language: state.language,
			terms: true,
		}).then(function (data) {
			busy(false);
			if (!data.ok) { message(data.message, 'error'); return; }
			state.token = data.token;
			state.email = data.email;
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
		busy(true, t('Checking the code …'));
		post('/api/verify', { token: state.token, code: code }).then(function (data) {
			busy(false);
			if (!data.ok) { message(data.message, 'error'); return; }
			step(6);
		}).catch(function () {
			busy(false);
			message(t('Something went wrong. Please try again.'), 'error');
		});
	});

	/* ---- step 6: the password, and only now is the account created ---- */
	var checkPassword = window.idregPassword.attach({
		input: 'idreg-password', repeat: 'idreg-password2', bar: 'idreg-meter-bar',
		hint: 'idreg-password-hint', rules: 'idreg-rules', eye: 'idreg-eye',
		minLength: conditions.minPasswordLength,
		onChange: function (ok) { passwordOk = ok; $('idreg-finish').disabled = !ok || inFlight > 0; },
	});

	$('idreg-generate').addEventListener('click', function () {
		window.idregPassword.fill({ input: 'idreg-password', repeat: 'idreg-password2', eye: 'idreg-eye', minLength: conditions.minPasswordLength });
	});

	$('idreg-finish').addEventListener('click', function () {
		if (!checkPassword()) { return; }
		busy(true, t('Creating the account …'));
		post('/api/finish', { token: state.token, password: $('idreg-password').value, handoff: handoff }).then(function (data) {
			busy(false);
			if (!data.ok) { message(data.message, 'error'); return; }
			$('idreg-done-text').textContent = data.status === 'awaiting_approval'
				? t('Thank you. An administrator still has to let you in; you will get an e-mail when the account is open.')
				: t('Your account is ready. Your user name is {uid}.', { uid: data.uid });
			$('idreg-login').href = loginUrl;
			$('idreg-login').hidden = data.status === 'awaiting_approval';
			step(7);
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
	if (!conditions.requirePhone) {
		$('idreg-phone').required = false;
		$('idreg-phone').previousElementSibling.textContent = t('Phone number (optional)');
	}
	if (conditions.termsUrl) {
		var termsLink = $('idreg-terms-link');
		termsLink.href = conditions.termsUrl;
		termsLink.hidden = false;
		$('idreg-terms-link-express').href = conditions.termsUrl;
		$('idreg-terms-link-express').hidden = false;
	}
	$('idreg-express-consent').hidden = !express;
	if (conditions.acceptDrivingLicence && conditions.acceptIdCard) {
		$('idreg-doc-lead').textContent = t('Hold your identity card or your driving licence in front of the camera. We read your name from it while you hold it; no picture is stored.');
	} else if (conditions.acceptDrivingLicence) {
		$('idreg-doc-lead').textContent = t('Hold your driving licence in front of the camera. We read your name from it while you hold it; no picture is stored.');
	}

	var saved = savedState();
	if (saved) {
		state.scanId = saved.scanId || '';
		state.token = saved.token || '';
		state.email = saved.email || '';
		state.card = saved.card;
		if (state.card) {
			$('idreg-given').value = state.card.givenNames || '';
			$('idreg-surname').value = state.card.surname || '';
			$('idreg-doc-type').textContent = state.card.type === 'driving_licence' ? t('Read from a driving licence.') : t('Read from an identity card.');
		}
		if (state.email) {
			$('idreg-sent').textContent = t('We sent a code to {email}. Type it here, or open the link in that e-mail.', { email: state.email });
		}
		// a step that needs the scan or the token cannot be shown without it
		var target = saved.step;
		if (target === 8) {
			var kept = null;
			try { kept = JSON.parse(window.sessionStorage.getItem('idregister.created') || 'null'); } catch (e) { kept = null; }
			if (kept && kept.uid) { showCreated(kept); return; }
			target = 1;
		}
		if (target >= 5 && !state.token) { target = 4; }
		if (target >= 2 && !state.scanId) { target = 1; }
		step(target);
	} else {
		step(1);
	}
	window.addEventListener('pagehide', stopSelfieCamera);
	if (debugScan) { window.idregStep = step; }
})();
