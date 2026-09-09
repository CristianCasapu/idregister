<?php

declare(strict_types=1);

namespace OCA\IdRegister\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;

/**
 * Passing the registration from a desktop to a phone.
 *
 * The desktop asks for a handoff, shows its link as a QR code and then follows what the phone
 * is doing. Everything lives in a small database table for half an hour (a cache would need
 * Redis or Memcached to be shared between requests, and not every server has one) and is
 * purged by the cleanup job.
 */
final class Handoff
{
    public const TTL = 1800;
    /** how long the desktop may pick up the sign-in after the phone finished */
    public const LOGIN_TTL = 600;
    public const STATE_WAITING = 'waiting';
    public const STATE_OPENED = 'opened';
    public const STATE_DOCUMENT = 'document';
    public const STATE_REGISTERED = 'registered';
    public const STATE_CONFIRMED = 'confirmed';

    private const TABLE = 'idregister_handoff';

    public function __construct(
        private IDBConnection $db,
        private IURLGenerator $urlGenerator,
        private ISecureRandom $random,
    ) {}

    /** @return array{token:string, url:string, secret:string} */
    public function create(): array
    {
        // the token goes into the QR code; the secret stays with whoever asked (the desktop page or
        // the app) and is needed to pick up the sign-in at the end, so a photographed QR code alone gives nothing
        $token = $this->random->generate(20, ISecureRandom::CHAR_ALPHANUMERIC);
        $secret = $this->random->generate(24, ISecureRandom::CHAR_ALPHANUMERIC);
        $this->write($token, ['state' => self::STATE_WAITING, 'name' => '', 'created' => time(), 'secret' => hash('sha256', $secret)], true);

        return ['token' => $token, 'url' => $this->url($token), 'secret' => $secret];
    }

    public function url(string $token): string
    {
        return $this->urlGenerator->linkToRouteAbsolute('idregister.page.index').'?s='.$token;
    }

    /** @return array{state:string, name:string, created:int, secret?:string, login?:array{uid:string, password:string, until:int}}|null */
    public function get(string $token): ?array
    {
        if ('' === $token || \strlen($token) > 64) {
            return null;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('data')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))
            ->andWhere($qb->expr()->gt('expires', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        if (false === $row) {
            return null;
        }
        $value = json_decode((string) $row['data'], true);

        return \is_array($value) ? $value : null;
    }

    public function advance(string $token, string $state, string $name = ''): void
    {
        $current = $this->get($token);
        if (null === $current) {
            return;
        }
        $order = [self::STATE_WAITING, self::STATE_OPENED, self::STATE_DOCUMENT, self::STATE_REGISTERED, self::STATE_CONFIRMED];
        // never go backwards, so a reload on the phone does not undo progress
        if (array_search($state, $order, true) < array_search($current['state'], $order, true)) {
            return;
        }
        $current['state'] = $state;
        if ('' !== $name) {
            $current['name'] = $name;
        }
        $this->write($token, $current);
    }

    /**
     * The phone finished: the desktop that showed the QR code may sign in as the new account,
     * once, within ten minutes. The credentials live only in the record of the hand-off.
     */
    public function finish(string $token, string $name, string $uid, string $password): void
    {
        $current = $this->get($token);
        if (null === $current) {
            return;
        }
        $current['state'] = self::STATE_CONFIRMED;
        $current['name'] = $name;
        $current['login'] = ['uid' => $uid, 'password' => $password, 'until' => time() + self::LOGIN_TTL];
        $this->write($token, $current);
    }

    /**
     * The credentials for the desktop — handed out once, then forgotten.
     *
     * @return array{uid:string, password:string}|null
     */
    public function takeLogin(string $token, string $secret): ?array
    {
        $current = $this->get($token);
        if (null === $current || !\is_array($current['login'] ?? null)) {
            return null;
        }
        if ('' === $secret || !hash_equals((string) ($current['secret'] ?? ''), hash('sha256', $secret))) {
            return null; // the QR code alone is not enough
        }
        $login = $current['login'];
        unset($current['login']);
        $this->write($token, $current);
        if ((int) ($login['until'] ?? 0) < time()) {
            return null;
        }

        return ['uid' => (string) $login['uid'], 'password' => (string) $login['password']];
    }

    /** Rows past their half hour; called by the cleanup job. */
    public function purge(): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)->where($qb->expr()->lt('expires', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement();
    }

    /** @param array{state:string, name:string, created:int, secret?:string, login?:array{uid:string, password:string, until:int}} $value */
    private function write(string $token, array $value, bool $new = false): void
    {
        $data = (string) json_encode($value);
        $expires = (int) ($value['created'] ?? time()) + self::TTL;
        $qb = $this->db->getQueryBuilder();
        if ($new) {
            $qb->insert(self::TABLE)->values([
                'token' => $qb->createNamedParameter($token),
                'data' => $qb->createNamedParameter($data),
                'created' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
                'expires' => $qb->createNamedParameter($expires, IQueryBuilder::PARAM_INT),
            ]);
        } else {
            $qb->update(self::TABLE)
                ->set('data', $qb->createNamedParameter($data))
                ->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));
        }
        $qb->executeStatement();
    }
}
