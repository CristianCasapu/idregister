<?php

declare(strict_types=1);

namespace OCA\IdRegister\Service;

use OCA\IdRegister\AppInfo\Application;
use OCA\IdRegister\Db\PendingRegistration;
use OCA\IdRegister\Db\PendingRegistrationMapper;
use OCP\Accounts\IAccountManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * The registration itself: a Nextcloud account is created straight away but stays disabled
 * until the e-mail address is confirmed with the code or the link. Nothing of the identity
 * card is kept beyond the name and a salted hash of the personal number.
 */
final class Registration
{
    public const CODE_LENGTH = 6;
    public const MAX_ATTEMPTS = 5;
    public const CODE_TTL = 1800;

    public function __construct(
        private PendingRegistrationMapper $mapper,
        private Settings $settings,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private IAccountManager $accountManager,
        private IMailer $mailer,
        private IURLGenerator $urlGenerator,
        private ISecureRandom $random,
        private ITimeFactory $time,
        private IDBConnection $db,
        private INotificationManager $notifications,
        private IL10N $l,
        private LoggerInterface $logger,
    ) {}

    /**
     * Create the (disabled) account and send the confirmation e-mail.
     *
     * @param array{surname:string, givenNames:string, cnp:string} $card
     *
     * @return array{token:string, email:string, uid:string}
     *
     * @throws \InvalidArgumentException on anything the visitor can fix
     */
    public function start(array $card, string $email, string $phone, string $password, string $language): array
    {
        if (!$this->settings->get('enabled')) {
            throw new \InvalidArgumentException($this->l->t('Registration is currently closed.'));
        }
        $surname = trim($card['surname']);
        $given = trim($card['givenNames']);
        if ('' === $surname || '' === $given) {
            throw new \InvalidArgumentException($this->l->t('The name could not be read from the identity card.'));
        }
        $email = mb_strtolower(trim($email));
        if (!$this->mailer->validateMailAddress($email)) {
            throw new \InvalidArgumentException($this->l->t('This e-mail address is not valid.'));
        }
        if (!$this->settings->emailAllowed($email)) {
            throw new \InvalidArgumentException($this->l->t('This e-mail domain cannot be used here.'));
        }
        $phone = self::normalizePhone($phone);
        if (null === $phone) {
            throw new \InvalidArgumentException($this->l->t('This phone number is not valid.'));
        }
        if (mb_strlen($password) < 10) {
            throw new \InvalidArgumentException($this->l->t('The password must have at least 10 characters.'));
        }

        // one account per person / per address
        if (\count($this->userManager->getByEmail($email)) > 0 || null !== $this->mapper->findByEmail($email)) {
            throw new \InvalidArgumentException($this->l->t('An account with this e-mail address already exists.'));
        }
        $cnpHash = '';
        if ('' !== $card['cnp']) {
            $cnpHash = hash('sha256', $card['cnp'].$this->settings->cnpSecret());
            if ($this->settings->get('oneAccountPerCard') && null !== $this->mapper->findByCnpHash($cnpHash)) {
                throw new \InvalidArgumentException($this->l->t('An account was already created with this identity card.'));
            }
        }

        $uid = $this->uniqueUid($given, $surname);
        $now = $this->time->getTime();

        $user = $this->userManager->createUser($uid, $password);
        if (!$user instanceof IUser) {
            throw new \RuntimeException('The account could not be created');
        }
        $user->setEnabled(false);
        $user->setDisplayName(IdCardParser::titleCase($given).' '.IdCardParser::titleCase($surname));
        $user->setSystemEMailAddress($email);
        $this->setPhone($user, $phone);
        if ('' !== (string) $this->settings->get('quota')) {
            $user->setQuota((string) $this->settings->get('quota'));
        }

        $pending = new PendingRegistration();
        $pending->setUid($uid);
        $pending->setSurname(IdCardParser::titleCase($surname));
        $pending->setGivenNames(IdCardParser::titleCase($given));
        $pending->setEmail($email);
        $pending->setPhone($phone);
        $pending->setCnpHash($cnpHash);
        $pending->setToken($this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC));
        $pending->setCode($this->newCode());
        $pending->setAttempts(0);
        $pending->setStatus(PendingRegistration::STATUS_PENDING);
        $pending->setCreatedAt($now);
        $pending->setExpiresAt($now + max(1, (int) $this->settings->get('expiryHours')) * 3600);
        $this->mapper->insert($pending);

        $this->sendVerificationMail($pending, $language);
        $this->logger->info('idregister: registration started for '.$uid.' ('.$email.')');

        return ['token' => $pending->getToken(), 'email' => $email, 'uid' => $uid];
    }

    /**
     * Confirm with the six digit code.
     *
     * @return array{status:string, uid:string, name:string}
     *
     * @throws \InvalidArgumentException
     */
    public function confirmWithCode(string $token, string $code): array
    {
        $pending = $this->findPending($token);
        if ($pending->getAttempts() >= self::MAX_ATTEMPTS) {
            throw new \InvalidArgumentException($this->l->t('Too many wrong codes. Ask for a new one.'));
        }
        if (!hash_equals($pending->getCode(), trim($code))) {
            $pending->setAttempts($pending->getAttempts() + 1);
            $this->mapper->update($pending);

            throw new \InvalidArgumentException($this->l->t('This code is not correct.'));
        }

        return $this->activate($pending);
    }

    /**
     * Confirm by opening the link in the e-mail.
     *
     * @return array{status:string, uid:string, name:string}
     *
     * @throws \InvalidArgumentException
     */
    public function confirmWithToken(string $token): array
    {
        return $this->activate($this->findPending($token));
    }

    public function resend(string $token, string $language): void
    {
        $pending = $this->findPending($token);
        $pending->setCode($this->newCode());
        $pending->setAttempts(0);
        $this->mapper->update($pending);
        $this->sendVerificationMail($pending, $language);
    }

    /** An administrator lets a confirmed registration through. */
    public function approve(int $id): void
    {
        $pending = $this->mapper->find($id);
        $user = $this->userManager->get($pending->getUid());
        if (null !== $user) {
            $user->setEnabled(true);
        }
        $pending->setStatus(PendingRegistration::STATUS_ACTIVE);
        $this->mapper->update($pending);
        $this->logger->info('idregister: '.$pending->getUid().' approved by an administrator');
    }

    /** Remove a registration; the account goes with it unless it is already active. */
    public function remove(int $id, bool $deleteUser = true): void
    {
        $pending = $this->mapper->find($id);
        if ($deleteUser && PendingRegistration::STATUS_ACTIVE !== $pending->getStatus()) {
            $user = $this->userManager->get($pending->getUid());
            $user?->delete();
        }
        $this->unlock($pending->getUid());
        $this->mapper->delete($pending);
    }

    /** @return int number of unconfirmed registrations removed */
    public function cleanup(): int
    {
        $removed = 0;
        foreach ($this->mapper->findExpired($this->time->getTime()) as $pending) {
            try {
                $this->remove((int) $pending->getId());
                ++$removed;
            } catch (\Throwable $e) {
                $this->logger->warning('idregister: could not remove the expired registration '.$pending->getUid(), ['exception' => $e]);
            }
        }

        return $removed;
    }

    /** @return array{status:string, uid:string, name:string} */
    private function activate(PendingRegistration $pending): array
    {
        $user = $this->userManager->get($pending->getUid());
        if (null === $user) {
            throw new \InvalidArgumentException($this->l->t('This registration no longer exists.'));
        }
        if (PendingRegistration::STATUS_PENDING !== $pending->getStatus()) {
            return ['status' => $pending->getStatus(), 'uid' => $pending->getUid(), 'name' => $pending->getFullName()];
        }

        // from now on the name, the e-mail address and the phone number are fixed
        $this->lock($pending);

        if ($this->settings->get('requireApproval')) {
            $pending->setStatus(PendingRegistration::STATUS_AWAITING_APPROVAL);
            $this->mapper->update($pending);
            $this->notifyAdmins($pending);
        } else {
            $user->setEnabled(true);
            $group = (string) $this->settings->get('defaultGroup');
            if ('' !== $group && $this->groupManager->groupExists($group)) {
                $this->groupManager->get($group)?->addUser($user);
            }
            $pending->setStatus(PendingRegistration::STATUS_ACTIVE);
            $this->mapper->update($pending);
        }
        $this->logger->info('idregister: '.$pending->getUid().' confirmed their e-mail address ('.$pending->getStatus().')');

        return ['status' => $pending->getStatus(), 'uid' => $pending->getUid(), 'name' => $pending->getFullName()];
    }

    private function findPending(string $token): PendingRegistration
    {
        try {
            $pending = $this->mapper->findByToken($token);
        } catch (DoesNotExistException $e) {
            throw new \InvalidArgumentException($this->l->t('This registration no longer exists.'));
        }
        if (PendingRegistration::STATUS_PENDING === $pending->getStatus() && $pending->getExpiresAt() < $this->time->getTime()) {
            throw new \InvalidArgumentException($this->l->t('This registration has expired. Please start again.'));
        }

        return $pending;
    }

    /** The values that may not change afterwards. */
    private function lock(PendingRegistration $pending): void
    {
        $query = $this->db->getQueryBuilder();
        $query->insert('idregister_locked')->values([
            'uid' => $query->createNamedParameter($pending->getUid()),
            'display_name' => $query->createNamedParameter($pending->getFullName()),
            'email' => $query->createNamedParameter($pending->getEmail()),
            'phone' => $query->createNamedParameter($pending->getPhone()),
            'created_at' => $query->createNamedParameter($this->time->getTime()),
        ]);

        try {
            $query->executeStatement();
        } catch (\Throwable $e) {
            // already locked
        }
    }

    private function unlock(string $uid): void
    {
        $query = $this->db->getQueryBuilder();
        $query->delete('idregister_locked')->where($query->expr()->eq('uid', $query->createNamedParameter($uid)))->executeStatement();
    }

    private function setPhone(IUser $user, string $phone): void
    {
        try {
            $account = $this->accountManager->getAccount($user);
            $account->setProperty(IAccountManager::PROPERTY_PHONE, $phone, IAccountManager::SCOPE_LOCAL, IAccountManager::NOT_VERIFIED);
            $this->accountManager->updateAccount($account);
        } catch (\Throwable $e) {
            $this->logger->warning('idregister: could not store the phone number of '.$user->getUID(), ['exception' => $e]);
        }
    }

    private function newCode(): string
    {
        return $this->random->generate(self::CODE_LENGTH, '0123456789');
    }

    private function sendVerificationMail(PendingRegistration $pending, string $language): void
    {
        $ro = str_starts_with($language, 'ro');
        $link = $this->urlGenerator->linkToRouteAbsolute('idregister.page.verify', ['token' => $pending->getToken()]);
        $code = $pending->getCode();
        $name = $pending->getFullName();

        $subject = $ro ? 'Confirmă adresa de e-mail' : 'Confirm your e-mail address';
        $heading = $ro ? 'Bun venit, '.$name : 'Welcome, '.$name;
        $body = $ro
            ? 'Codul tău de confirmare este '.$code.'. Este valabil 30 de minute. Poți folosi și butonul de mai jos.'
            : 'Your confirmation code is '.$code.'. It is valid for 30 minutes. You can also use the button below.';
        $buttonText = $ro ? 'Confirmă contul' : 'Confirm the account';
        $footer = $ro
            ? 'Dacă nu tu ai cerut acest cont, ignoră acest mesaj: contul se șterge singur.'
            : 'If you did not ask for this account, ignore this message: it deletes itself.';

        $template = $this->mailer->createEMailTemplate('idregister.Verify', ['code' => $code, 'link' => $link]);
        $template->setSubject($subject);
        $template->addHeader();
        $template->addHeading($heading);
        $template->addBodyText($body);
        $template->addBodyButton($buttonText, $link);
        $template->addBodyText($ro ? 'Cod: '.$code : 'Code: '.$code);
        $template->addFooter($footer);

        $message = $this->mailer->createMessage();
        $message->setTo([$pending->getEmail() => $name]);
        $message->useTemplate($template);
        $this->mailer->send($message);
    }

    private function notifyAdmins(PendingRegistration $pending): void
    {
        foreach ($this->groupManager->get('admin')?->getUsers() ?? [] as $admin) {
            $notification = $this->notifications->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($admin->getUID())
                ->setObject('registration', (string) $pending->getId())
                ->setDateTime(new \DateTime())
                ->setSubject('approval_needed', ['name' => $pending->getFullName(), 'email' => $pending->getEmail()])
            ;
            $this->notifications->notify($notification);
        }
    }

    /** "Ion-Marin Popescu" → "ion-marin.popescu", "…2" when taken */
    private function uniqueUid(string $given, string $surname): string
    {
        $slug = static function (string $s): string {
            $s = IdCardParser::stripDiacritics($s);
            $s = mb_strtolower($s);
            $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;

            return trim($s, '-');
        };
        $first = $slug(explode(' ', trim($given))[0] ?? $given);
        $last = $slug($surname);
        $base = trim($first.'.'.$last, '.');
        if ('' === $base) {
            $base = 'user';
        }
        $base = substr($base, 0, 48);

        $uid = $base;
        for ($i = 2; $this->userManager->userExists($uid); ++$i) {
            $uid = $base.$i;
            if ($i > 200) {
                $uid = $base.'-'.$this->random->generate(6, ISecureRandom::CHAR_LOWER.ISecureRandom::CHAR_DIGITS);

                break;
            }
        }

        return $uid;
    }

    /** Romanian and international numbers to E.164; null when it cannot be one. */
    public static function normalizePhone(string $raw): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', trim($raw)) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        }
        if (str_starts_with($digits, '0') && 10 === \strlen($digits)) {
            $digits = '+4'.$digits; // 07xx xxx xxx → +407xx xxx xxx
        }
        if (!str_starts_with($digits, '+')) {
            $digits = '+'.$digits;
        }
        $bare = substr($digits, 1);

        return (ctype_digit($bare) && \strlen($bare) >= 9 && \strlen($bare) <= 15) ? $digits : null;
    }
}
