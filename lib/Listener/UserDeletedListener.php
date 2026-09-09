<?php

declare(strict_types=1);

namespace OCA\IdRegister\Listener;

use OCA\IdRegister\Db\PendingRegistrationMapper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IDBConnection;
use OCP\User\Events\UserDeletedEvent;

/**
 * When an account goes, its registration record, its locked values and its Google link go with it.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
final class UserDeletedListener implements IEventListener
{
    public function __construct(private IDBConnection $db, private PendingRegistrationMapper $mapper) {}

    public function handle(Event $event): void
    {
        if (!$event instanceof UserDeletedEvent) {
            return;
        }
        $uid = $event->getUser()->getUID();

        try {
            $this->mapper->delete($this->mapper->findByUid($uid));
        } catch (\Throwable $e) {
            // no registration for this account
        }
        foreach (['idregister_locked', 'idregister_google'] as $table) {
            $query = $this->db->getQueryBuilder();
            $query->delete($table)
                ->where($query->expr()->eq('uid', $query->createNamedParameter($uid)))
                ->executeStatement()
            ;
        }
    }
}
