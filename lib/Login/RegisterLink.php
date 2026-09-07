<?php

declare(strict_types=1);

namespace OCA\IdRegister\Login;

use OCA\IdRegister\Service\Settings;
use OCP\Authentication\IAlternativeLogin;
use OCP\Authentication\IAlternativeLoginProvider;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Puts "Create an account with your identity card" under the sign-in form, so visitors
 * who land on the login page can find the registration.
 */
final class RegisterLink implements IAlternativeLogin, IAlternativeLoginProvider
{
    public function __construct(
        private Settings $settings,
        private IURLGenerator $urlGenerator,
        private IL10N $l,
    ) {}

    public function getAlternativeLogins(): array
    {
        return $this->settings->get('registrationOpen') ? [$this] : [];
    }

    public function getLabel(): string
    {
        return $this->l->t('Create an account with your identity card');
    }

    public function getLink(): string
    {
        return $this->urlGenerator->linkToRoute('idregister.page.index');
    }

    public function getClass(): string
    {
        return 'idregister-login-link';
    }

    public function load(): void
    {
        \OCP\Util::addStyle('idregister', 'login');
    }
}
