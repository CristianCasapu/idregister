<?php

declare(strict_types=1);

namespace OCA\IdRegister\Settings;

use OCA\IdRegister\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

final class AdminSection implements IIconSection
{
    public function __construct(private IL10N $l, private IURLGenerator $urlGenerator) {}

    public function getID(): string
    {
        return Application::APP_ID;
    }

    public function getName(): string
    {
        return $this->l->t('ID card registration');
    }

    public function getPriority(): int
    {
        return 75;
    }

    public function getIcon(): string
    {
        return $this->urlGenerator->imagePath('core', 'actions/user.svg');
    }
}
