<?php
declare(strict_types=1);
\OCP\Util::addStyle('idregister', 'register');
\OCP\Util::addScript('idregister', 'vendor/qrcode');
\OCP\Util::addScript('idregister', 'password');
\OCP\Util::addScript('idregister', 'register');
/** @var \OCP\IL10N $l */
?>
<div id="idregister" class="idreg">
	<noscript><p class="idreg-note"><?php p($l->t('This page needs JavaScript.')); ?></p></noscript>

	<div class="idreg-card">
		<h1><?php p($l->t('Create your account')); ?></h1>

		<div class="idreg-stepline">
			<span class="idreg-stepcount" id="idreg-stepcount"></span>
			<span class="idreg-steps" aria-hidden="true">
				<span class="dot on" data-dot="1"></span><span class="dot" data-dot="2"></span><span class="dot" data-dot="3"></span><span class="dot" data-dot="4"></span><span class="dot" data-dot="5"></span><span class="dot" data-dot="6"></span>
			</span>
		</div>

		<div class="idreg-message" id="idreg-message" role="alert" hidden></div>

		<!-- 0: on a computer, hand over to a phone -->
		<section class="idreg-step" data-step="0" hidden>
			<p class="idreg-lead"><?php p($l->t('You need a camera and your document in hand, so this is done on a phone or a tablet. Scan this code with its camera.')); ?></p>
			<div class="idreg-qr"><canvas id="idreg-qr"></canvas></div>
			<p class="idreg-hint" id="idreg-qr-url"></p>
			<ol class="idreg-progress" id="idreg-progress">
				<li data-state="opened"><?php p($l->t('Opened on the phone')); ?></li>
				<li data-state="document"><?php p($l->t('Document read')); ?></li>
				<li data-state="registered"><?php p($l->t('Account created')); ?></li>
				<li data-state="confirmed"><?php p($l->t('E-mail address confirmed')); ?></li>
			</ol>
		</section>

		<!-- 1: the document, read live with the camera -->
		<section class="idreg-step" data-step="1" hidden>
			<p class="idreg-lead" id="idreg-doc-lead"><?php p($l->t('Hold your identity card in front of the camera. We read your name from it while you hold it; no picture is stored.')); ?></p>
			<div class="idreg-cam" id="idreg-cam">
				<video id="idreg-video" playsinline muted autoplay></video>
				<canvas id="idreg-overlay" aria-hidden="true"></canvas>
				<div class="idreg-cam-status" id="idreg-cam-status" role="status"><?php p($l->t('Starting the camera …')); ?></div>
				<div class="idreg-cam-bar">
					<button type="button" class="idreg-cam-button" id="idreg-torch" hidden>💡 <span><?php p($l->t('Torch')); ?></span></button>
					<button type="button" class="idreg-cam-button primary" id="idreg-use" disabled><?php p($l->t('Use what was read')); ?></button>
				</div>
			</div>
			<p class="idreg-hint" id="idreg-cam-hint"><?php p($l->t('Dark background, no flash or reflections. It is captured by itself once the personal number is confirmed.')); ?></p>
			<p class="idreg-hint"><a href="#" id="idreg-photo-link"><?php p($l->t('Cannot use the camera? Photograph the document')); ?></a></p>
			<div id="idreg-photo" hidden>
				<label class="idreg-capture" for="idreg-file">
					<span class="idreg-capture-icon" aria-hidden="true">📷</span>
					<span id="idreg-capture-text"><?php p($l->t('Photograph the document')); ?></span>
				</label>
				<input type="file" id="idreg-file" accept="image/*" capture="environment" hidden>
				<img id="idreg-preview" alt="" hidden>
				<p class="idreg-hint"><?php p($l->t('Put the whole document in the frame, on a dark surface, without reflections.')); ?></p>
				<button type="button" class="idreg-button primary" id="idreg-scan" disabled><?php p($l->t('Read the document')); ?></button>
			</div>
		</section>

		<!-- 2: what was read -->
		<section class="idreg-step" data-step="2" hidden>
			<p class="idreg-lead"><?php p($l->t('This is what we read. The name comes from the document and cannot be changed.')); ?></p>
			<div class="idreg-field">
				<label for="idreg-given"><?php p($l->t('Given names')); ?></label>
				<input type="text" id="idreg-given" readonly>
			</div>
			<div class="idreg-field">
				<label for="idreg-surname"><?php p($l->t('Surname')); ?></label>
				<input type="text" id="idreg-surname" readonly>
			</div>
			<p class="idreg-hint" id="idreg-doc-type"></p>
			<div class="idreg-buttons">
				<button type="button" class="idreg-button" id="idreg-again"><?php p($l->t('Scan again')); ?></button>
				<button type="button" class="idreg-button primary" id="idreg-confirm-card"><?php p($l->t('This is me')); ?></button>
			</div>
		</section>

		<!-- 3: the selfie -->
		<section class="idreg-step" data-step="3" hidden>
			<p class="idreg-lead"><?php p($l->t('Now a selfie, so we can see that the document is yours. It is compared with the photo on the document and then deleted.')); ?></p>
			<div class="idreg-cam selfie" id="idreg-selfie-cam">
				<video id="idreg-selfie-video" playsinline muted autoplay></video>
				<canvas id="idreg-selfie-overlay" aria-hidden="true"></canvas>
				<div class="idreg-cam-status" id="idreg-selfie-status" role="status"><?php p($l->t('Starting the camera …')); ?></div>
				<div class="idreg-cam-bar">
					<button type="button" class="idreg-cam-button primary" id="idreg-take-selfie" disabled><?php p($l->t('Take the selfie')); ?></button>
				</div>
			</div>
			<p class="idreg-hint"><?php p($l->t('Put your face inside the oval, in good light, without sunglasses or a hat.')); ?></p>
			<p class="idreg-hint"><a href="#" id="idreg-selfie-photo-link"><?php p($l->t('Cannot use the camera? Take a picture')); ?></a></p>
			<div id="idreg-selfie-photo" hidden>
				<label class="idreg-capture" for="idreg-selfie-file">
					<span class="idreg-capture-icon" aria-hidden="true">🙂</span>
					<span id="idreg-selfie-text"><?php p($l->t('Take a selfie')); ?></span>
				</label>
				<input type="file" id="idreg-selfie-file" accept="image/*" capture="user" hidden>
				<img id="idreg-selfie-preview" alt="" hidden>
				<button type="button" class="idreg-button primary" id="idreg-check-selfie" disabled><?php p($l->t('Check the selfie')); ?></button>
			</div>
			<div class="idreg-buttons">
				<button type="button" class="idreg-button" id="idreg-back-selfie"><?php p($l->t('Back')); ?></button>
			</div>
		</section>

		<!-- 4: contact details -->
		<section class="idreg-step" data-step="4" hidden>
			<div class="idreg-field">
				<label for="idreg-email"><?php p($l->t('E-mail address')); ?></label>
				<input type="email" id="idreg-email" autocomplete="email" inputmode="email" required>
				<span class="idreg-sub"><?php p($l->t('We send a confirmation code here. It cannot be changed later.')); ?></span>
			</div>
			<div class="idreg-field">
				<label for="idreg-phone"><?php p($l->t('Phone number')); ?></label>
				<input type="tel" id="idreg-phone" autocomplete="tel" inputmode="tel" placeholder="07xx xxx xxx" required>
			</div>
			<label class="idreg-check">
				<input type="checkbox" id="idreg-terms">
				<span>
					<?php p($l->t('I agree that my name is taken from my document and that my e-mail address and phone number are stored. The pictures of the document and of my face, and my personal number, are not kept.')); ?>
					<a id="idreg-terms-link" href="#" target="_blank" rel="noopener" hidden><?php p($l->t('Read more')); ?></a>
				</span>
			</label>
			<div class="idreg-buttons">
				<button type="button" class="idreg-button" id="idreg-back-2"><?php p($l->t('Back')); ?></button>
				<button type="button" class="idreg-button primary" id="idreg-submit"><?php p($l->t('Continue')); ?></button>
			</div>
		</section>

		<!-- 5: e-mail confirmation -->
		<section class="idreg-step" data-step="5" hidden>
			<p class="idreg-lead" id="idreg-sent"></p>
			<div class="idreg-field">
				<label for="idreg-code"><?php p($l->t('Confirmation code')); ?></label>
				<input type="text" id="idreg-code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]*" placeholder="000000">
			</div>
			<div class="idreg-buttons">
				<button type="button" class="idreg-button" id="idreg-resend"><?php p($l->t('Send a new code')); ?></button>
				<button type="button" class="idreg-button primary" id="idreg-verify"><?php p($l->t('Confirm')); ?></button>
			</div>
			<p class="idreg-hint"><?php p($l->t('You can also just open the link in the e-mail.')); ?></p>
		</section>

		<!-- 6: the password, and only now is the account created -->
		<section class="idreg-step" data-step="6" hidden>
			<p class="idreg-lead"><?php p($l->t('Last step: choose a password. Your account is created when you press the button.')); ?></p>
			<div class="idreg-field">
				<label for="idreg-password"><?php p($l->t('Password')); ?></label>
				<div class="idreg-password-wrap">
					<input type="password" id="idreg-password" autocomplete="new-password" minlength="10" required>
					<button type="button" class="idreg-eye" id="idreg-eye" aria-label="<?php p($l->t('Show the password')); ?>">👁</button>
				</div>
				<div class="idreg-meter"><span id="idreg-meter-bar"></span></div>
				<span class="idreg-sub" id="idreg-password-hint"></span>
				<ul class="idreg-rules" id="idreg-rules">
					<li data-rule="length"><?php p($l->t('At least 10 characters')); ?></li>
					<li data-rule="case"><?php p($l->t('Small and capital letters')); ?></li>
					<li data-rule="digit"><?php p($l->t('At least one digit')); ?></li>
					<li data-rule="symbol"><?php p($l->t('A symbol makes it stronger')); ?></li>
				</ul>
				<button type="button" class="idreg-generate" id="idreg-generate"><?php p($l->t('Generate a password for me')); ?></button>
			</div>
			<div class="idreg-field">
				<label for="idreg-password2"><?php p($l->t('Repeat the password')); ?></label>
				<input type="password" id="idreg-password2" autocomplete="new-password" required>
			</div>
			<button type="button" class="idreg-button primary" id="idreg-finish" disabled><?php p($l->t('Create the account')); ?></button>
		</section>

		<!-- 7: done -->
		<section class="idreg-step" data-step="7" hidden>
			<p class="idreg-done" id="idreg-done-text"></p>
			<a class="idreg-button primary" id="idreg-login" href="#"><?php p($l->t('Sign in')); ?></a>
		</section>

		<div class="idreg-spinner" id="idreg-spinner" hidden><span class="idreg-spin" aria-hidden="true"></span><span id="idreg-spinner-text"><?php p($l->t('Working …')); ?></span></div>
	</div>
</div>
