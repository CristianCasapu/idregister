<?php
declare(strict_types=1);
\OCP\Util::addStyle('idregister', 'register');
/** @var \OCP\IL10N $l */
/** @var array $_ */
?>
<div id="idregister-google" class="idreg">
	<div class="idreg-card">
		<h1><?php p($l->t('Signing in with Google did not work')); ?></h1>
		<p class="idreg-message error"><?php p($_['message']); ?></p>
		<a class="idreg-button primary" href="<?php p($_['loginUrl']); ?>"><?php p($l->t('Back to the sign-in page')); ?></a>
		<?php if ('' !== ($_['registerUrl'] ?? '')): ?>
			<a class="idreg-button" href="<?php p($_['registerUrl']); ?>"><?php p($l->t('Create an account with your identity card')); ?></a>
		<?php endif; ?>
	</div>
</div>
