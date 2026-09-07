<?php

declare(strict_types=1);

namespace OCA\IdRegister\Listener;

use OCP\Accounts\IAccountManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\User\Events\UserChangedEvent;
use Psr\Log\LoggerInterface;

/**
 * The name comes from an identity card and the e-mail address was verified, so neither may be
 * changed afterwards; the phone number is fixed as well. Nextcloud has no immutable account
 * fields, so any change to one of them is put back.
 *
 * @template-implements IEventListener<UserChangedEvent>
 */
final class LockedFieldsListener implements IEventListener
{
    /** guards against reacting to our own correction */
    private static bool $restoring = false;

    public function __construct(
        private IDBConnection $db,
        private IUserManager $userManager,
        private IAccountManager $accountManager,
        private LoggerInterface $logger,
    ) {}

    public function handle(Event $event): void
    {
        if (!$event instanceof UserChangedEvent || self::$restoring) {
            return;
        }
        $feature = $event->getFeature();
        if (!\in_array($feature, ['displayName', 'eMail'], true)) {
            return;
        }
        $user = $event->getUser();
        $locked = $this->locked($user->getUID());
        if (null === $locked) {
            return;
        }

        $expected = 'displayName' === $feature ? $locked['display_name'] : $locked['email'];
        if ('' === $expected || (string) $event->getValue() === $expected) {
            return;
        }

        self::$restoring = true;

        try {
            if ('displayName' === $feature) {
                $user->setDisplayName($expected);
            } else {
                $user->setSystemEMailAddress($expected);
            }
            $this->logger->info('idregister: '.$feature.' of '.$user->getUID().' put back to the value from the identity card');
        } catch (\Throwable $e) {
            $this->logger->warning('idregister: could not restore '.$feature.' of '.$user->getUID(), ['exception' => $e]);
        } finally {
            self::$restoring = false;
        }

        // the phone number lives in the account properties and is checked on the same occasion
        $this->restorePhone($user->getUID(), $locked['phone']);
    }

    private function restorePhone(string $uid, string $expected): void
    {
        if ('' === $expected) {
            return;
        }
        $user = $this->userManager->get($uid);
        if (null === $user) {
            return;
        }

        try {
            $account = $this->accountManager->getAccount($user);
            if ($account->getProperty(IAccountManager::PROPERTY_PHONE)->getValue() === $expected) {
                return;
            }
            $account->setProperty(IAccountManager::PROPERTY_PHONE, $expected, IAccountManager::SCOPE_LOCAL, IAccountManager::NOT_VERIFIED);
            $this->accountManager->updateAccount($account);
        } catch (\Throwable $e) {
            // the property may not exist yet
        }
    }

    /** @return array{display_name:string, email:string, phone:string}|null */
    private function locked(string $uid): ?array
    {
        $query = $this->db->getQueryBuilder();
        $query->select('display_name', 'email', 'phone')->from('idregister_locked')
            ->where($query->expr()->eq('uid', $query->createNamedParameter($uid)))
        ;
        $row = $query->executeQuery()->fetch();

        return $row ? [
            'display_name' => (string) $row['display_name'],
            'email' => (string) $row['email'],
            'phone' => (string) $row['phone'],
        ] : null;
    }
}
