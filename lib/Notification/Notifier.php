<?php

declare(strict_types=1);

namespace OCA\IdRegister\Notification;

use OCA\IdRegister\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

final class Notifier implements INotifier
{
    public function __construct(private IFactory $l10nFactory, private IURLGenerator $urlGenerator) {}

    public function getID(): string
    {
        return Application::APP_ID;
    }

    public function getName(): string
    {
        return $this->l10nFactory->get(Application::APP_ID)->t('Sign up with ID');
    }

    public function prepare(INotification $notification, string $languageCode): INotification
    {
        if (Application::APP_ID !== $notification->getApp() || 'approval_needed' !== $notification->getSubject()) {
            throw new UnknownNotificationException();
        }
        $l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
        $params = $notification->getSubjectParameters();

        $notification->setParsedSubject($l->t('A registration is waiting for approval'))
            ->setParsedMessage($l->t('%1$s (%2$s) confirmed their e-mail address and is waiting to be let in.', [(string) ($params['name'] ?? ''), (string) ($params['email'] ?? '')]))
            ->setLink($this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => 'idregister']))
            ->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('core', 'actions/user.svg')))
        ;

        return $notification;
    }
}
