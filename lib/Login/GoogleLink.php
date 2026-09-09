<?php

declare(strict_types=1);

namespace OCA\IdRegister\Login;

use OCA\IdRegister\Service\Google;
use OCP\Authentication\IAlternativeLogin;
use OCP\Authentication\IAlternativeLoginProvider;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * "Continue with Google" under the sign-in form, for accounts that have linked a Google account.
 */
final class GoogleLink implements IAlternativeLogin, IAlternativeLoginProvider
{
    public function __construct(
        private Google $google,
        private IURLGenerator $urlGenerator,
        private IRequest $request,
        private IL10N $l,
    ) {}

    public function getAlternativeLogins(): array
    {
        return $this->google->enabled() ? [$this] : [];
    }

    public function getLabel(): string
    {
        return $this->l->t('Continue with Google');
    }

    public function getLink(): string
    {
        // whatever page the visitor was sent away from is carried through Google and back
        $redirect = (string) $this->request->getParam('redirect_url', '');

        return $this->urlGenerator->linkToRoute('idregister.google.login', '' === $redirect ? [] : ['redirect_url' => $redirect]);
    }

    public function getClass(): string
    {
        return 'idregister-google-link';
    }

    public function load(): void
    {
        \OCP\Util::addStyle('idregister', 'login');
    }
}
