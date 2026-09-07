<?php
declare(strict_types=1);
\OCP\Util::addStyle('idregister', 'register');
/** @var \OCP\IL10N $l */
/** @var array $_ */
?>
<div id="idregister" class="idreg">
	<div class="idreg-card">
		<?php if ('' !== ($_['error'] ?? '')): ?>
			<h1><?php p($l->t('The account could not be confirmed')); ?></h1>
			<p class="idreg-message error"><?php p($_['error']); ?></p>
			<a class="idreg-button" href="<?php p(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('idregister.page.index')); ?>"><?php p($l->t('Start again')); ?></a>
		<?php elseif ('awaiting_approval' === ($_['result']['status'] ?? '')): ?>
			<h1><?php p($l->t('E-mail address confirmed')); ?></h1>
			<p class="idreg-done"><?php p($l->t('Thank you, %s. An administrator still has to let you in; you will get an e-mail when the account is open.', [$_['result']['name'] ?? ''])); ?></p>
		<?php else: ?>
			<h1><?php p($l->t('Your account is ready')); ?></h1>
			<p class="idreg-done"><?php p($l->t('Welcome, %s. You can sign in now.', [$_['result']['name'] ?? ''])); ?></p>
			<p class="idreg-hint"><?php p($l->t('Your user name is %s.', [$_['result']['uid'] ?? ''])); ?></p>
			<a class="idreg-button primary" href="<?php p($_['loginUrl']); ?>"><?php p($l->t('Sign in')); ?></a>
		<?php endif; ?>
	</div>
</div>
