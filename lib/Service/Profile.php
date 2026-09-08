<?php

declare(strict_types=1);

namespace OCA\IdRegister\Service;

use OCA\IdRegister\AppInfo\Application;
use OCA\IdRegister\Listener\LockedFieldsListener;
use OCP\Accounts\IAccountManager;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * What an express account adds after signing in: the e-mail address (confirmed with a code,
 * then fixed), the phone number (fixed once set) and a nickname (free to change).
 */
final class Profile
{
    private const CODE_TTL = 1800;
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private IConfig $config,
        private IUserManager $userManager,
        private IAccountManager $accountManager,
        private IMailer $mailer,
        private ISecureRandom $random,
        private Registration $registration,
        private Settings $settings,
        private IL10N $l,
        private LoggerInterface $logger,
    ) {}

    /** @return array{uid:string, name:string, email:string, emailLocked:bool, pendingEmail:string, phone:string, phoneLocked:bool, nickname:string, express:bool} */
    public function state(IUser $user): array
    {
        $uid = $user->getUID();
        $locked = $this->registration->lockedRow($uid);
        $phone = '';
        try {
            $phone = (string) $this->accountManager->getAccount($user)->getProperty(IAccountManager::PROPERTY_PHONE)->getValue();
        } catch (\Throwable $e) {
        }

        return [
            'uid' => $uid,
            'name' => $user->getDisplayName(),
            'email' => (string) $user->getSystemEMailAddress(),
            'emailLocked' => null !== $locked && '' !== $locked['email'],
            'pendingEmail' => $this->config->getUserValue($uid, Application::APP_ID, 'pendingEmail', ''),
            'phone' => $phone,
            'phoneLocked' => null !== $locked && '' !== $locked['phone'],
            'nickname' => $this->config->getUserValue($uid, Application::APP_ID, 'nickname', ''),
            'express' => null !== $locked && '' === $locked['email'],
        ];
    }

    /** Ask for an address: a code goes there, nothing changes until it comes back. */
    public function requestEmail(IUser $user, string $email, string $language): void
    {
        $email = mb_strtolower(trim($email));
        if ('' === $email) {
            // "use another address": forget the code that was sent
            $this->clearPending($user->getUID());

            return;
        }
        if (!$this->mailer->validateMailAddress($email)) {
            throw new \InvalidArgumentException($this->l->t('This e-mail address is not valid.'));
        }
        if (!$this->settings->emailAllowed($email)) {
            throw new \InvalidArgumentException($this->l->t('This e-mail domain cannot be used here.'));
        }
        foreach ($this->userManager->getByEmail($email) as $other) {
            if ($other->getUID() !== $user->getUID()) {
                throw new \InvalidArgumentException($this->l->t('An account with this e-mail address already exists.'));
            }
        }
        $locked = $this->registration->lockedRow($user->getUID());
        if (null !== $locked && '' !== $locked['email']) {
            throw new \InvalidArgumentException($this->l->t('Your e-mail address is confirmed and cannot be changed.'));
        }
        $code = $this->random->generate(6, '0123456789');
        $this->config->setUserValue($user->getUID(), Application::APP_ID, 'pendingEmail', $email);
        $this->config->setUserValue($user->getUID(), Application::APP_ID, 'pendingCode', $code);
        $this->config->setUserValue($user->getUID(), Application::APP_ID, 'pendingUntil', (string) (time() + self::CODE_TTL));
        $this->config->setUserValue($user->getUID(), Application::APP_ID, 'pendingAttempts', '0');

        try {
            $this->registration->sendProfileCode($email, $user->getDisplayName(), $code, $language);
        } catch (\Throwable $e) {
            $this->logger->error('idregister: the profile code could not be sent to '.$email, ['exception' => $e]);
            $this->clearPending($user->getUID());

            throw new \InvalidArgumentException($this->l->t('The confirmation e-mail could not be sent. Please check the address, or try again later.'));
        }
    }

    /** The code came back: the address becomes the account's and is fixed. */
    public function confirmEmail(IUser $user, string $code): string
    {
        $uid = $user->getUID();
        $email = $this->config->getUserValue($uid, Application::APP_ID, 'pendingEmail', '');
        $expected = $this->config->getUserValue($uid, Application::APP_ID, 'pendingCode', '');
        $until = (int) $this->config->getUserValue($uid, Application::APP_ID, 'pendingUntil', '0');
        $attempts = (int) $this->config->getUserValue($uid, Application::APP_ID, 'pendingAttempts', '0');
        if ('' === $email || '' === $expected) {
            throw new \InvalidArgumentException($this->l->t('Ask for a code first.'));
        }
        if (time() > $until || $attempts >= self::MAX_ATTEMPTS) {
            $this->clearPending($uid);

            throw new \InvalidArgumentException($this->l->t('This code has expired. Ask for a new one.'));
        }
        if (!hash_equals($expected, trim($code))) {
            $this->config->setUserValue($uid, Application::APP_ID, 'pendingAttempts', (string) ($attempts + 1));

            throw new \InvalidArgumentException($this->l->t('This code is not correct.'));
        }
        LockedFieldsListener::$allow = true;
        try {
            $user->setSystemEMailAddress($email);
        } finally {
            LockedFieldsListener::$allow = false;
        }
        $this->registration->lockValue($uid, 'email', $email);
        $this->clearPending($uid);
        $this->logger->info('idregister: '.$uid.' confirmed the e-mail address '.$email);

        return $email;
    }

    public function setPhone(IUser $user, string $phone): string
    {
        $locked = $this->registration->lockedRow($user->getUID());
        if (null !== $locked && '' !== $locked['phone']) {
            throw new \InvalidArgumentException($this->l->t('Your phone number is fixed and cannot be changed.'));
        }
        $normalized = Registration::normalizePhone($phone);
        if (null === $normalized) {
            throw new \InvalidArgumentException($this->l->t('This phone number is not valid.'));
        }
        $account = $this->accountManager->getAccount($user);
        $account->setProperty(IAccountManager::PROPERTY_PHONE, $normalized, IAccountManager::SCOPE_LOCAL, IAccountManager::NOT_VERIFIED);
        $this->accountManager->updateAccount($account);
        $this->registration->lockValue($user->getUID(), 'phone', $normalized);

        return $normalized;
    }

    public function setNickname(IUser $user, string $nickname): string
    {
        $nickname = trim(preg_replace('/\s+/u', ' ', $nickname) ?? '');
        if (mb_strlen($nickname) > 40) {
            throw new \InvalidArgumentException($this->l->t('The nickname is too long (40 characters at most).'));
        }
        $this->config->setUserValue($user->getUID(), Application::APP_ID, 'nickname', $nickname);

        return $nickname;
    }

    private function clearPending(string $uid): void
    {
        foreach (['pendingEmail', 'pendingCode', 'pendingUntil', 'pendingAttempts'] as $key) {
            $this->config->deleteUserValue($uid, Application::APP_ID, $key);
        }
    }
}
