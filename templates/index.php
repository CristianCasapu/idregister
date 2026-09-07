<?php
declare(strict_types=1);
\OCP\Util::addStyle('idregister', 'register');
\OCP\Util::addScript('idregister', 'register');
/** @var \OCP\IL10N $l */
?>
<div id="idregister" class="idreg">
	<noscript><p class="idreg-note"><?php p($l->t('This page needs JavaScript.')); ?></p></noscript>

	<div class="idreg-card">
		<h1><?php p($l->t('Create your account')); ?></h1>

		<div class="idreg-steps" aria-hidden="true">
			<span class="dot on" data-dot="1"></span><span class="dot" data-dot="2"></span><span class="dot" data-dot="3"></span><span class="dot" data-dot="4"></span>
		</div>

		<div class="idreg-message" id="idreg-message" role="alert" hidden></div>

		<!-- 1: the identity card -->
		<section class="idreg-step" data-step="1">
			<p class="idreg-lead"><?php p($l->t('Take a picture of your identity card. We read your name from it and then delete the picture — it is never stored.')); ?></p>
			<label class="idreg-capture" for="idreg-file">
				<span class="idreg-capture-icon" aria-hidden="true">📷</span>
				<span id="idreg-capture-text"><?php p($l->t('Photograph the identity card')); ?></span>
			</label>
			<input type="file" id="idreg-file" accept="image/*" capture="environment" hidden>
			<img id="idreg-preview" alt="" hidden>
			<p class="idreg-hint"><?php p($l->t('Put the whole card in the frame, on a dark surface, without reflections.')); ?></p>
			<button type="button" class="idreg-button primary" id="idreg-scan" disabled><?php p($l->t('Read the card')); ?></button>
		</section>

		<!-- 2: what was read -->
		<section class="idreg-step" data-step="2" hidden>
			<p class="idreg-lead"><?php p($l->t('This is what we read. The name comes from the identity card and cannot be changed.')); ?></p>
			<div class="idreg-field">
				<label for="idreg-given"><?php p($l->t('Given names')); ?></label>
				<input type="text" id="idreg-given" readonly>
			</div>
			<div class="idreg-field">
				<label for="idreg-surname"><?php p($l->t('Surname')); ?></label>
				<input type="text" id="idreg-surname" readonly>
			</div>
			<div class="idreg-buttons">
				<button type="button" class="idreg-button" id="idreg-again"><?php p($l->t('Take another picture')); ?></button>
				<button type="button" class="idreg-button primary" id="idreg-confirm-card"><?php p($l->t('This is me')); ?></button>
			</div>
		</section>

		<!-- 3: contact details -->
		<section class="idreg-step" data-step="3" hidden>
			<div class="idreg-field">
				<label for="idreg-email"><?php p($l->t('E-mail address')); ?></label>
				<input type="email" id="idreg-email" autocomplete="email" inputmode="email" required>
				<span class="idreg-sub"><?php p($l->t('We send a confirmation code here. It cannot be changed later.')); ?></span>
			</div>
			<div class="idreg-field">
				<label for="idreg-phone"><?php p($l->t('Phone number')); ?></label>
				<input type="tel" id="idreg-phone" autocomplete="tel" inputmode="tel" placeholder="07xx xxx xxx" required>
			</div>
			<div class="idreg-field">
				<label for="idreg-password"><?php p($l->t('Password')); ?></label>
				<input type="password" id="idreg-password" autocomplete="new-password" minlength="10" required>
				<span class="idreg-sub"><?php p($l->t('At least 10 characters.')); ?></span>
			</div>
			<label class="idreg-check">
				<input type="checkbox" id="idreg-terms">
				<span><?php p($l->t('I agree that my name is taken from my identity card and that my e-mail address and phone number are stored. The picture of the card and my personal number are not kept.')); ?></span>
			</label>
			<div class="idreg-buttons">
				<button type="button" class="idreg-button" id="idreg-back-2"><?php p($l->t('Back')); ?></button>
				<button type="button" class="idreg-button primary" id="idreg-submit"><?php p($l->t('Create the account')); ?></button>
			</div>
		</section>

		<!-- 4: e-mail confirmation -->
		<section class="idreg-step" data-step="4" hidden>
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

		<!-- 5: done -->
		<section class="idreg-step" data-step="5" hidden>
			<p class="idreg-done" id="idreg-done-text"></p>
			<a class="idreg-button primary" id="idreg-login" href="#"><?php p($l->t('Sign in')); ?></a>
		</section>

		<div class="idreg-spinner" id="idreg-spinner" hidden><span></span><?php p($l->t('Working …')); ?></div>
	</div>
</div>
