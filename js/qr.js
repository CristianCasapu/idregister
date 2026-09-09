/* Draws a QR code into a canvas. Shared by the registration hand-off, the pairing of a phone
   and the sign-in with a phone. */
(function () {
	'use strict';
	window.idregDrawQr = function (canvas, text, max) {
		if (!canvas || typeof qrcode === 'undefined') { return false; }
		try {
			var qr = qrcode(0, 'M');
			qr.addData(text);
			qr.make();
			var count = qr.getModuleCount();
			var quiet = 4;
			var room = Math.min(max || 300, window.innerWidth - 80);
			var cell = Math.max(3, Math.floor(room / (count + quiet * 2)));
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
			return true;
		} catch (e) {
			return false;
		}
	};
})();
