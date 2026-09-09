<?php

declare(strict_types=1);

namespace OCA\IdRegister\AppInfo;

use OCA\IdRegister\Listener\LockedFieldsListener;
use OCA\IdRegister\Listener\UserDeletedListener;
use OCA\IdRegister\Notification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\User\Events\UserChangedEvent;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap
{
    public const APP_ID = 'idregister';

    public function __construct(array $urlParams = [])
    {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void
    {
        // name, e-mail and phone of a user registered with an identity card stay as they were read
        $context->registerEventListener(UserChangedEvent::class, LockedFieldsListener::class);
        $context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
        $context->registerNotifierService(Notifier::class);
        $context->registerSetupCheck(\OCA\IdRegister\SetupChecks\TesseractCheck::class);
        // "Create an account with your identity card" and "Continue with Google" under the sign-in form
        foreach ([\OCA\IdRegister\Login\RegisterLink::class, \OCA\IdRegister\Login\GoogleLink::class] as $login) {
            if (method_exists($context, 'registerAlternativeLoginProvider')) {
                $context->registerAlternativeLoginProvider($login);
            } else {
                $context->registerAlternativeLogin($login);
            }
        }
    }

    public function boot(IBootContext $context): void {}
}
