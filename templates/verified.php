<?php
declare(strict_types=1);
\OCP\Util::addStyle('idregister', 'register');
\OCP\Util::addScript('idregister', 'password');
\OCP\Util::addScript('idregister', 'verified');
/** @var \OCP\IL10N $l */
/** @var array $_ */
?>
<div id="idregister-verified" class="idreg">
	<div class="idreg-card">
		<?php if ('' !== ($_['error'] ?? '')): ?>
			<h1><?php p($l->t('The account could not be confirmed')); ?></h1>
			<p class="idreg-message error"><?php p($_['error']); ?></p>
			<a class="idreg-button" href="<?php p(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('idregister.page.index')); ?>"><?php p($l->t('Start again')); ?></a>
		<?php elseif ('verified' === ($_['result']['status'] ?? '')): ?>
			<h1><?php p($l->t('E-mail address confirmed')); ?></h1>
			<p class="idreg-lead"><?php p($l->t('Last step: choose a password. Your account is created when you press the button.')); ?></p>
			<div class="idreg-message" id="idreg-message" role="alert" hidden></div>
			<div class="idreg-field">
				<label for="idreg-password"><?php p($l->t('Password')); ?></label>
				<div class="idreg-password-wrap">
					<input type="password" id="idreg-password" autocomplete="new-password" required>
					<button type="button" class="idreg-eye" id="idreg-eye" aria-label="<?php p($l->t('Show the password')); ?>">&#128065;</button>
				</div>
				<div class="idreg-meter"><span id="idreg-meter-bar"></span></div>
				<span class="idreg-sub" id="idreg-password-hint"></span>
				<ul class="idreg-rules" id="idreg-rules">
					<li data-rule="length"><?php p($l->t('At least 10 characters')); ?></li>
					<li data-rule="case"><?php p($l->t('Small and capital letters')); ?></li>
					<li data-rule="digit"><?php p($l->t('At least one digit')); ?></li>
					<li data-rule="symbol"><?php p($l->t('A symbol makes it stronger')); ?></li>
				</ul>
			</div>
			<div class="idreg-field">
				<label for="idreg-password2"><?php p($l->t('Repeat the password')); ?></label>
				<input type="password" id="idreg-password2" autocomplete="new-password" required>
			</div>
			<button type="button" class="idreg-button primary" id="idreg-finish" disabled><?php p($l->t('Create the account')); ?></button>
			<div class="idreg-spinner" id="idreg-spinner" hidden><span></span><span id="idreg-spinner-text"><?php p($l->t('Working …')); ?></span></div>
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
