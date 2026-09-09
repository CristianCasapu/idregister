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
        return 'IDRegister';
    }

    public function prepare(INotification $notification, string $languageCode): INotification
    {
        if (Application::APP_ID !== $notification->getApp()) {
            throw new UnknownNotificationException();
        }
        $l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
        $params = $notification->getSubjectParameters();
        $personal = $this->urlGenerator->linkToRouteAbsolute('settings.PersonalSettings.index', ['section' => 'personal-info']);

        match ($notification->getSubject()) {
            // to the administrators
            'approval_needed' => $notification
                ->setParsedSubject($l->t('A registration is waiting for approval'))
                ->setParsedMessage($l->t('%1$s (%2$s) confirmed their e-mail address and is waiting to be let in.', [(string) ($params['name'] ?? ''), (string) ($params['email'] ?? '')]))
                ->setLink($this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => 'idregister']))
                ->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('core', 'actions/user.svg'))),
            // to the account itself: everything that changes how it can be signed in to
            'phone_paired' => $notification
                ->setParsedSubject($l->t('A phone was paired with your account'))
                ->setParsedMessage($l->t('"%s" can sign you in from now on. If this was not you, remove it in your personal settings and change your password.', [(string) ($params['name'] ?? '')]))
                ->setLink($personal)
                ->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('core', 'clients/phone.svg'))),
            'phone_removed' => $notification
                ->setParsedSubject($l->t('A phone was unpaired from your account'))
                ->setParsedMessage($l->t('"%s" can no longer sign in to your account. If this was not you, change your password now.', [(string) ($params['name'] ?? '')]))
                ->setLink($personal)
                ->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('core', 'clients/phone.svg'))),
            'phone_signin' => $notification
                ->setParsedSubject($l->t('You were signed in by your phone'))
                ->setParsedMessage($l->t('"%1$s" approved a sign-in from %2$s (%3$s). If this was not you, remove the phone in your personal settings and change your password.', [(string) ($params['name'] ?? ''), (string) ($params['browser'] ?? ''), (string) ($params['ip'] ?? '')]))
                ->setLink($personal)
                ->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('core', 'clients/phone.svg'))),
            'google_linked' => $notification
                ->setParsedSubject($l->t('A Google account was linked to your account'))
                ->setParsedMessage($l->t('%s can sign you in from now on. If this was not you, unlink it in your personal settings and change your password.', [(string) ($params['email'] ?? '')]))
                ->setLink($personal)
                ->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('core', 'actions/password.svg'))),
            default => throw new UnknownNotificationException(),
        };

        return $notification;
    }
}
