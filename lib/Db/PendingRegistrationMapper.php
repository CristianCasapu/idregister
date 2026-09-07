<?php

declare(strict_types=1);

namespace OCA\IdRegister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<PendingRegistration>
 */
class PendingRegistrationMapper extends QBMapper
{
    public const TABLE = 'idregister_pending';

    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, self::TABLE, PendingRegistration::class);
    }

    /** @throws \OCP\AppFramework\Db\DoesNotExistException */
    public function findByToken(string $token): PendingRegistration
    {
        $query = $this->db->getQueryBuilder();
        $query->select('*')->from(self::TABLE)
            ->where($query->expr()->eq('token', $query->createNamedParameter($token)))
        ;

        return $this->findEntity($query);
    }

    /** @throws \OCP\AppFramework\Db\DoesNotExistException */
    public function findByUid(string $uid): PendingRegistration
    {
        $query = $this->db->getQueryBuilder();
        $query->select('*')->from(self::TABLE)
            ->where($query->expr()->eq('uid', $query->createNamedParameter($uid)))
        ;

        return $this->findEntity($query);
    }

    public function findByEmail(string $email): ?PendingRegistration
    {
        $query = $this->db->getQueryBuilder();
        $query->select('*')->from(self::TABLE)
            ->where($query->expr()->eq('email', $query->createNamedParameter(mb_strtolower($email))))
            ->setMaxResults(1)
        ;

        try {
            return $this->findEntity($query);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function findByCnpHash(string $hash): ?PendingRegistration
    {
        if ('' === $hash) {
            return null;
        }
        $query = $this->db->getQueryBuilder();
        $query->select('*')->from(self::TABLE)
            ->where($query->expr()->eq('cnp_hash', $query->createNamedParameter($hash)))
            ->setMaxResults(1)
        ;

        try {
            return $this->findEntity($query);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return list<PendingRegistration> */
    public function findAll(?string $status = null, int $limit = 500): array
    {
        $query = $this->db->getQueryBuilder();
        $query->select('*')->from(self::TABLE)->orderBy('created_at', 'DESC')->setMaxResults($limit);
        if (null !== $status) {
            $query->where($query->expr()->eq('status', $query->createNamedParameter($status)));
        }

        return $this->findEntities($query);
    }

    /** @return list<PendingRegistration> registrations that were never confirmed */
    public function findExpired(int $now): array
    {
        $query = $this->db->getQueryBuilder();
        $query->select('*')->from(self::TABLE)
            ->where($query->expr()->eq('status', $query->createNamedParameter(PendingRegistration::STATUS_PENDING)))
            ->andWhere($query->expr()->lt('expires_at', $query->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
        ;

        return $this->findEntities($query);
    }
}
