<?php
declare(strict_types=1);
\OCP\Util::addStyle('idregister', 'register');
\OCP\Util::addScript('idregister', 'vendor/qrcode');
\OCP\Util::addScript('idregister', 'qr');
\OCP\Util::addScript('idregister', 'phone');
/** @var \OCP\IL10N $l */
?>
<div id="idregister-phone" class="idreg">
	<div class="idreg-card">
		<h1><?php p($l->t('Sign in with your phone')); ?></h1>
		<p class="idreg-lead" id="idreg-phone-lead"><?php p($l->t('Open the registration app on your phone, press "Sign in", and point it at this code.')); ?></p>
		<div class="idreg-message" id="idreg-phone-message" role="alert" hidden></div>
		<canvas id="idreg-phone-qr" width="1" height="1" aria-label="<?php p($l->t('Sign-in code')); ?>"></canvas>
		<div class="idreg-number" id="idreg-phone-number" hidden>
			<span class="idreg-sub"><?php p($l->t('Choose these digits on your phone')); ?></span>
			<strong id="idreg-phone-digits">··</strong>
		</div>
		<div class="idreg-sub" id="idreg-phone-countdown"></div>
		<button type="button" class="idreg-button" id="idreg-phone-again" hidden><?php p($l->t('Show a new code')); ?></button>
		<div class="idreg-links">
			<a href="<?php p(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('core.login.showLoginForm')); ?>"><?php p($l->t('Back to the sign-in page')); ?></a>
		</div>
	</div>
</div>
