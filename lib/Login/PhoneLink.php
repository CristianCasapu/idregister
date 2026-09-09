<?php

declare(strict_types=1);

namespace OCA\IdRegister\Login;

use OCA\IdRegister\Service\Devices;
use OCP\Authentication\IAlternativeLogin;
use OCP\Authentication\IAlternativeLoginProvider;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;

/** "Sign in with your phone" under the sign-in form, for accounts that paired one. */
final class PhoneLink implements IAlternativeLogin, IAlternativeLoginProvider
{
    public function __construct(
        private Devices $devices,
        private IURLGenerator $urlGenerator,
        private IRequest $request,
        private IL10N $l,
    ) {}

    public function getAlternativeLogins(): array
    {
        return $this->devices->enabled() ? [$this] : [];
    }

    public function getLabel(): string
    {
        return $this->l->t('Sign in with your phone');
    }

    public function getLink(): string
    {
        $redirect = (string) $this->request->getParam('redirect_url', '');

        return $this->urlGenerator->linkToRoute('idregister.device.page', '' === $redirect ? [] : ['redirect_url' => $redirect]);
    }

    public function getClass(): string
    {
        return 'idregister-phone-link';
    }

    public function load(): void
    {
        \OCP\Util::addStyle('idregister', 'login');
    }
}
