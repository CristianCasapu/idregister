<?php

declare(strict_types=1);

namespace OCA\IdRegister\Service;

use OCP\Authentication\Token\IToken;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Signs a user in without a password, once something else has proved who they are (a linked
 * Google account, for now).
 *
 * The steps are the ones the sign-in form itself goes through — complete the login, create the
 * session token, and, if the account has a second factor, hand over to the challenge page instead
 * of letting the session through. Two of them are only on the private session class; the public
 * interface has no way to sign in without a password.
 *
 * @see \OC\Authentication\Login\Chain
 */
final class SignIn
{
    public function __construct(
        private IUserSession $userSession,
        private IURLGenerator $urlGenerator,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return string where to send the browser: the second factor, or the page that was asked for
     *
     * @throws \RuntimeException when the session cannot sign anybody in
     * @throws \Exception when the account is disabled, or an app cancels the login
     */
    public function complete(IUser $user, IRequest $request, string $redirectUrl = ''): string
    {
        $session = $this->userSession;
        if (!$session instanceof \OC\User\Session) {
            throw new \RuntimeException('the session does not support signing in without a password');
        }
        $uid = $user->getUID();
        $remember = 0 !== $this->config->getSystemValueInt('remember_login_cookie_lifetime', 60 * 60 * 24 * 15);

        $session->completeLogin($user, ['loginName' => $uid, 'password' => '']);
        $session->createSessionToken($request, $uid, $uid, null, $remember ? IToken::REMEMBER : IToken::DO_NOT_REMEMBER);

        $twoFactor = \OCP\Server::get(\OC\Authentication\TwoFactorAuth\Manager::class);
        if ($twoFactor->isTwoFactorAuthenticated($user)) {
            // without this the session would count as fully signed in and the second factor
            // would simply be skipped
            $twoFactor->prepareTwoFactorLogin($user, $remember);
            $set = $twoFactor->getProviderSet($user);
            $providers = $set->getPrimaryProviders();
            $params = '' === $redirectUrl ? [] : ['redirect_url' => $redirectUrl];
            $mandatory = \OCP\Server::get(\OC\Authentication\TwoFactorAuth\MandatoryTwoFactor::class);
            if ([] === $providers && !$set->isProviderMissing() && [] !== $twoFactor->getLoginSetupProviders($user) && $mandatory->isEnforcedFor($user)) {
                // a second factor is required of this account but none is set up yet
                return $this->urlGenerator->linkToRoute('core.TwoFactorChallenge.setupProviders', $params);
            }
            if (1 === \count($providers) && !$set->isProviderMissing()) {
                $provider = array_pop($providers);

                return $this->urlGenerator->linkToRoute('core.TwoFactorChallenge.showChallenge', $params + ['challengeProviderId' => $provider->getId()]);
            }

            return $this->urlGenerator->linkToRoute('core.TwoFactorChallenge.selectChallenge', $params);
        }

        if ($remember && !$this->config->getSystemValueBool('auto_logout', false)) {
            // this is what keeps the browser signed in afterwards
            $session->createRememberMeToken($user);
        }
        $this->logger->info('idregister: '.$uid.' signed in without a password');

        return '' !== $redirectUrl ? $redirectUrl : $this->urlGenerator->linkToDefaultPageUrl();
    }
}
