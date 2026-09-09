<?php

declare(strict_types=1);

namespace OCA\IdRegister\Service;

use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Signing in with a paired phone.
 *
 * The phone holds a key pair it made in the Android key store: the private half never leaves the
 * device and is only usable after a fingerprint, and the server keeps nothing but the public half.
 * Pairing starts from the account itself (the password is asked again) and is proved by a signature
 * with the fresh key, so only the phone that made the key can finish it.
 *
 * A sign-in is a request the server writes down and the phone signs: the browser keeps a secret and
 * shows two digits, the phone shows what is being approved and asks for those two digits among
 * three, and the signature covers the server, the request, its nonce, the digits and the time. The
 * request lives two minutes, is used once, and the session is only ever handed to the browser that
 * holds the secret.
 *
 * What this cannot do is make the person sure that the screen they are looking at is really this
 * server: somebody could show a sign-in request of their own and ask for it to be scanned. The
 * digits and the details shown on the phone are there to make that obvious; a passkey is the only
 * thing that removes the question entirely.
 */
final class Devices
{
    public const PAIR_TTL = 300;
    public const AUTH_TTL = 120;
    /** How far the clock of a phone may be off. */
    public const SIGN_SKEW = 300;

    public const STATE_WAITING = 'waiting';
    public const STATE_APPROVED = 'approved';
    public const STATE_DENIED = 'denied';
    public const STATE_TAKEN = 'taken';

    public function __construct(
        private IDBConnection $db,
        private IUserManager $userManager,
        private ISecureRandom $random,
        private Settings $settings,
        private INotificationManager $notifications,
        private IMailer $mailer,
        private IConfig $config,
        private IL10N $l,
        private LoggerInterface $logger,
        private \OCP\IURLGenerator $urlGenerator,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('phoneLoginEnabled');
    }

    /** The address the phone talks to, and the first thing in every signature. */
    public function serverUrl(): string
    {
        return $this->urlGenerator->linkToRouteAbsolute('idregister.page.index');
    }

    // ---------------------------------------------------------------- pairing

    /**
     * Ask for a pairing: the password is required again, so a hijacked session cannot quietly
     * add a phone of its own.
     *
     * @return array{token:string, payload:string, expires:int}
     */
    public function startPairing(IUser $user, string $password): array
    {
        $this->requireEnabled();
        if ('' === $password || !$this->userManager->checkPassword($user->getUID(), $password)) {
            throw new \InvalidArgumentException($this->l->t('That password is not right.'));
        }
        $this->forgetExpired();
        $token = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
        $insert = $this->db->getQueryBuilder();
        $insert->insert('idregister_pairing')->values([
            'token' => $insert->createNamedParameter($token),
            'uid' => $insert->createNamedParameter($user->getUID()),
            'created' => $insert->createNamedParameter(time(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
            'expires' => $insert->createNamedParameter(time() + self::PAIR_TTL, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
        ])->executeStatement();

        return [
            'token' => $token,
            'payload' => (string) json_encode(['v' => 1, 't' => 'pair', 's' => $this->serverUrl(), 'p' => $token]),
            'expires' => time() + self::PAIR_TTL,
        ];
    }

    /** Has the phone finished? Polled by the settings page. */
    public function pairingState(string $uid, string $token): array
    {
        $row = $this->pairingRow($token);
        if (null === $row || $row['uid'] !== $uid) {
            return ['state' => 'gone'];
        }
        if ('' !== $row['device_id']) {
            // the page knows now; the code, which names the account, need not stay
            $query = $this->db->getQueryBuilder();
            $query->delete('idregister_pairing')
                ->where($query->expr()->eq('token', $query->createNamedParameter($token)))
                ->executeStatement()
            ;

            return ['state' => 'done'];
        }

        return ['state' => 'waiting'];
    }

    /**
     * The phone read the code: it sends the public half of a key it has just made, and signs the
     * pairing token with the private half to prove it holds it.
     *
     * @return array{account:string, name:string, deviceId:string}
     */
    public function completePairing(string $token, string $publicKey, string $name, string $signature): array
    {
        $this->requireEnabled();
        $row = $this->pairingRow($token);
        if (null === $row) {
            throw new \InvalidArgumentException($this->l->t('This pairing code has expired. Ask for a new one.'));
        }
        if ('' !== $row['device_id']) {
            throw new \InvalidArgumentException($this->l->t('This pairing code has already been used.'));
        }
        $publicKey = trim($publicKey);
        $message = implode("\n", ['idregister-pair-v1', $this->serverUrl(), $token]);
        if (!$this->verify($publicKey, $message, $signature)) {
            throw new \InvalidArgumentException($this->l->t('The phone could not prove that it holds the key.'));
        }
        $user = $this->userManager->get($row['uid']);
        if (null === $user) {
            throw new \InvalidArgumentException($this->l->t('This account no longer exists.'));
        }

        $deviceId = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $name = '' === $name ? $this->l->t('Phone') : mb_substr($name, 0, 60);
        $insert = $this->db->getQueryBuilder();
        $insert->insert('idregister_device')->values([
            'uid' => $insert->createNamedParameter($row['uid']),
            'device_id' => $insert->createNamedParameter($deviceId),
            'name' => $insert->createNamedParameter($name),
            'public_key' => $insert->createNamedParameter($publicKey),
            'algorithm' => $insert->createNamedParameter('ES256'),
            'created_at' => $insert->createNamedParameter(time(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
        ])->executeStatement();

        $update = $this->db->getQueryBuilder();
        $update->update('idregister_pairing')
            ->set('device_id', $update->createNamedParameter($deviceId))
            ->where($update->expr()->eq('token', $update->createNamedParameter($token)))
            ->executeStatement()
        ;
        $this->logger->info('idregister: '.$row['uid'].' paired the phone "'.$name.'"');
        // a phone that can sign this account in is worth saying out loud
        $this->tell((string) $row['uid'], 'phone_paired', $deviceId, ['name' => $name]);
        $this->mail(
            (string) $row['uid'],
            ['ro' => 'Un telefon a fost împerecheat cu contul tău', 'en' => 'A phone was paired with your account'],
            [
                'ro' => 'Telefonul „'.$name.'” îți poate deschide contul de acum, fără parolă. Dacă nu tu ai făcut asta, elimină-l din Setări personale și schimbă-ți parola.',
                'en' => 'The phone "'.$name.'" can sign in to your account from now on, without a password. If this was not you, remove it in your personal settings and change your password.',
            ],
        );

        return ['account' => $row['uid'], 'name' => $user->getDisplayName(), 'deviceId' => $deviceId];
    }

    /** @return list<array{deviceId:string, name:string, created:int, lastUsed:int}> */
    public function devices(string $uid): array
    {
        $query = $this->db->getQueryBuilder();
        $query->select('device_id', 'name', 'created_at', 'last_used')->from('idregister_device')
            ->where($query->expr()->eq('uid', $query->createNamedParameter($uid)))
            ->orderBy('created_at', 'ASC')
        ;
        $out = [];
        foreach ($query->executeQuery()->fetchAll() as $row) {
            $out[] = [
                'deviceId' => (string) $row['device_id'],
                'name' => (string) $row['name'],
                'created' => (int) $row['created_at'],
                'lastUsed' => (int) $row['last_used'],
            ];
        }

        return $out;
    }

    /** Forget a phone. The password is asked again here too. */
    public function forget(IUser $user, string $deviceId, string $password): void
    {
        if ('' === $password || !$this->userManager->checkPassword($user->getUID(), $password)) {
            throw new \InvalidArgumentException($this->l->t('That password is not right.'));
        }
        $device = $this->device($deviceId);
        $query = $this->db->getQueryBuilder();
        $query->delete('idregister_device')
            ->where($query->expr()->eq('uid', $query->createNamedParameter($user->getUID())))
            ->andWhere($query->expr()->eq('device_id', $query->createNamedParameter($deviceId)))
        ;
        if (0 === $query->executeStatement()) {
            return;
        }
        $this->logger->info('idregister: '.$user->getUID().' removed a paired phone');
        $this->removed($user->getUID(), (string) ($device['name'] ?? ''));
    }

    /**
     * The phone says it is done: it signs the same way it signs a sign-in, so a phone that
     * somebody picked up cannot quietly untie itself — the fingerprint is asked for first.
     *
     * @return array{name:string}
     */
    public function forgetSigned(string $deviceId, int $timestamp, string $signature): array
    {
        $device = $this->device($deviceId);
        if (null === $device) {
            // already gone here; the phone may drop it too
            return ['name' => ''];
        }
        if (abs(time() - $timestamp) > self::SIGN_SKEW) {
            throw new \InvalidArgumentException($this->l->t('The clock of the phone is too far off.'));
        }
        $message = implode("\n", ['idregister-forget-v1', $this->serverUrl(), $deviceId, (string) $timestamp]);
        if (!$this->verify((string) $device['public_key'], $message, $signature)) {
            throw new \InvalidArgumentException($this->l->t('The phone could not prove that it holds the key.'));
        }
        $query = $this->db->getQueryBuilder();
        $query->delete('idregister_device')
            ->where($query->expr()->eq('device_id', $query->createNamedParameter($deviceId)))
            ->executeStatement()
        ;
        $this->logger->info('idregister: a paired phone removed itself from '.$device['uid']);
        $this->removed((string) $device['uid'], (string) $device['name']);

        return ['name' => (string) $device['name']];
    }

    /** The same, from the personal settings; the password was asked there. */
    public function forgetAll(string $uid): void
    {
        foreach (['idregister_device', 'idregister_pairing'] as $table) {
            $query = $this->db->getQueryBuilder();
            $query->delete($table)->where($query->expr()->eq('uid', $query->createNamedParameter($uid)))->executeStatement();
        }
    }

    // --------------------------------------------------------------- sign-in

    /**
     * A browser asks to be signed in by a phone.
     *
     * @return array{requestId:string, secret:string, number:string, payload:string, expires:int}
     */
    public function startLogin(IRequest $request, string $redirect = ''): array
    {
        $this->requireEnabled();
        $this->forgetExpired();
        $requestId = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
        $secret = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
        $nonce = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
        $number = str_pad((string) random_int(0, 99), 2, '0', STR_PAD_LEFT);
        $choices = [$number];
        while (\count($choices) < 3) {
            $candidate = str_pad((string) random_int(0, 99), 2, '0', STR_PAD_LEFT);
            if (!\in_array($candidate, $choices, true)) {
                $choices[] = $candidate;
            }
        }
        shuffle($choices);

        $insert = $this->db->getQueryBuilder();
        $insert->insert('idregister_authreq')->values([
            'request_id' => $insert->createNamedParameter($requestId),
            'secret_hash' => $insert->createNamedParameter(hash('sha256', $secret)),
            'nonce' => $insert->createNamedParameter($nonce),
            'number' => $insert->createNamedParameter($number),
            'choices' => $insert->createNamedParameter(implode(',', $choices)),
            'state' => $insert->createNamedParameter(self::STATE_WAITING),
            'browser' => $insert->createNamedParameter(mb_substr(Device::describe((string) $request->getHeader('User-Agent')), 0, 250)),
            'ip' => $insert->createNamedParameter(mb_substr($request->getRemoteAddress(), 0, 45)),
            'redirect' => $insert->createNamedParameter(mb_substr($redirect, 0, 250)),
            'created' => $insert->createNamedParameter(time(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
            'expires' => $insert->createNamedParameter(time() + self::AUTH_TTL, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
        ])->executeStatement();

        return [
            'requestId' => $requestId,
            'secret' => $secret,
            'number' => $number,
            'payload' => (string) json_encode(['v' => 1, 't' => 'auth', 's' => $this->serverUrl(), 'r' => $requestId, 'n' => $nonce]),
            'expires' => time() + self::AUTH_TTL,
        ];
    }

    /**
     * What the phone shows before asking for a fingerprint: who is asking, from where, and the
     * three numbers to choose from.
     *
     * @return array{browser:string, ip:string, choices:list<string>, seconds:int}
     */
    public function describe(string $requestId, string $nonce): array
    {
        $row = $this->authRow($requestId);
        if (null === $row || !hash_equals((string) $row['nonce'], $nonce)) {
            throw new \InvalidArgumentException($this->l->t('This sign-in has expired. Please start again.'));
        }
        if (self::STATE_WAITING !== $row['state']) {
            throw new \InvalidArgumentException($this->l->t('This sign-in has already been answered.'));
        }

        return [
            'browser' => (string) $row['browser'],
            'ip' => (string) $row['ip'],
            'choices' => explode(',', (string) $row['choices']),
            'seconds' => max(0, (int) $row['expires'] - time()),
        ];
    }

    /** The phone says yes: the signature has to cover everything that matters. */
    public function approve(string $requestId, string $deviceId, string $number, int $timestamp, string $signature): string
    {
        $this->requireEnabled();
        $row = $this->authRow($requestId);
        if (null === $row) {
            throw new \InvalidArgumentException($this->l->t('This sign-in has expired. Please start again.'));
        }
        if (self::STATE_WAITING !== $row['state']) {
            throw new \InvalidArgumentException($this->l->t('This sign-in has already been answered.'));
        }
        $device = $this->device($deviceId);
        if (null === $device) {
            throw new \InvalidArgumentException($this->l->t('This phone is not paired with any account here.'));
        }
        if (abs(time() - $timestamp) > self::SIGN_SKEW) {
            throw new \InvalidArgumentException($this->l->t('The clock of the phone is too far off.'));
        }
        if (!hash_equals((string) $row['number'], $number)) {
            throw new \InvalidArgumentException($this->l->t('Those are not the digits shown on the screen.'));
        }
        $message = implode("\n", [
            'idregister-auth-v1',
            $this->serverUrl(),
            $requestId,
            (string) $row['nonce'],
            $number,
            (string) $timestamp,
        ]);
        if (!$this->verify((string) $device['public_key'], $message, $signature)) {
            throw new \InvalidArgumentException($this->l->t('The phone could not prove that it holds the key.'));
        }
        $user = $this->userManager->get((string) $device['uid']);
        if (null === $user || !$user->isEnabled()) {
            throw new \InvalidArgumentException($this->l->t('This account is waiting for an administrator.'));
        }

        $update = $this->db->getQueryBuilder();
        $update->update('idregister_authreq')
            ->set('state', $update->createNamedParameter(self::STATE_APPROVED))
            ->set('uid', $update->createNamedParameter((string) $device['uid']))
            ->set('device_id', $update->createNamedParameter($deviceId))
            ->where($update->expr()->eq('request_id', $update->createNamedParameter($requestId)))
            ->andWhere($update->expr()->eq('state', $update->createNamedParameter(self::STATE_WAITING)))
        ;
        if (1 !== $update->executeStatement()) {
            throw new \InvalidArgumentException($this->l->t('This sign-in has already been answered.'));
        }
        $touch = $this->db->getQueryBuilder();
        $touch->update('idregister_device')
            ->set('last_used', $touch->createNamedParameter(time(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
            ->where($touch->expr()->eq('device_id', $touch->createNamedParameter($deviceId)))
            ->executeStatement()
        ;
        $this->logger->info('idregister: a paired phone approved a sign-in for '.$device['uid']);
        $this->tell((string) $device['uid'], 'phone_signin', $requestId, [
            'name' => (string) $device['name'],
            'browser' => (string) $row['browser'],
            'ip' => (string) $row['ip'],
        ]);

        return (string) $device['uid'];
    }

    /** The phone says no. Nothing is signed: the request is simply closed. */
    public function deny(string $requestId, string $nonce): void
    {
        $row = $this->authRow($requestId);
        if (null === $row || !hash_equals((string) $row['nonce'], $nonce)) {
            return;
        }
        $update = $this->db->getQueryBuilder();
        $update->update('idregister_authreq')
            ->set('state', $update->createNamedParameter(self::STATE_DENIED))
            ->where($update->expr()->eq('request_id', $update->createNamedParameter($requestId)))
            ->andWhere($update->expr()->eq('state', $update->createNamedParameter(self::STATE_WAITING)))
            ->executeStatement()
        ;
    }

    /**
     * The browser asks whether it may come in. The session is handed over once, and only to the
     * browser that started the request.
     *
     * @return array{state:string, user:IUser|null, redirect:string}
     */
    public function collect(string $requestId, string $secret): array
    {
        $row = $this->authRow($requestId);
        if (null === $row) {
            return ['state' => 'expired', 'user' => null, 'redirect' => ''];
        }
        if (!hash_equals((string) $row['secret_hash'], hash('sha256', $secret))) {
            return ['state' => 'expired', 'user' => null, 'redirect' => ''];
        }
        if (self::STATE_DENIED === $row['state']) {
            // the browser has been told; the row has nothing left to say
            $this->drop($requestId);

            return ['state' => self::STATE_DENIED, 'user' => null, 'redirect' => ''];
        }
        if (self::STATE_APPROVED !== $row['state']) {
            return ['state' => (string) $row['state'], 'user' => null, 'redirect' => ''];
        }
        // deleting rather than marking: the row carries the account, the address it was asked from
        // and the browser, and once the session is handed over nothing needs any of it
        $delete = $this->db->getQueryBuilder();
        $delete->delete('idregister_authreq')
            ->where($delete->expr()->eq('request_id', $delete->createNamedParameter($requestId)))
            ->andWhere($delete->expr()->eq('state', $delete->createNamedParameter(self::STATE_APPROVED)))
        ;
        if (1 !== $delete->executeStatement()) {
            return ['state' => self::STATE_TAKEN, 'user' => null, 'redirect' => ''];
        }

        return [
            'state' => self::STATE_APPROVED,
            'user' => $this->userManager->get((string) $row['uid']),
            'redirect' => (string) $row['redirect'],
        ];
    }

    private function removed(string $uid, string $name): void
    {
        $this->tell($uid, 'phone_removed', $name, ['name' => $name]);
        $this->mail(
            $uid,
            ['ro' => 'Un telefon a fost dezlegat de contul tău', 'en' => 'A phone was unpaired from your account'],
            [
                'ro' => 'Telefonul „'.$name.'” nu mai poate deschide contul tău. Dacă nu tu ai făcut asta, schimbă-ți parola acum.',
                'en' => 'The phone "'.$name.'" can no longer sign in to your account. If this was not you, change your password now.',
            ],
        );
    }

    /**
     * A letter about the things that change how this account can be signed in to. It goes out
     * beside the notification, because a phone that is taken away is exactly the moment when the
     * notifications on it are of no use.
     */
    private function mail(string $uid, array $subject, array $body): void
    {
        try {
            $user = $this->userManager->get($uid);
            $address = null === $user ? '' : (string) $user->getSystemEMailAddress();
            if ('' === $address) {
                return;
            }
            $ro = str_starts_with($this->config->getUserValue($uid, 'core', 'lang', 'ro'), 'ro');
            $pick = static fn (array $text): string => $ro ? $text['ro'] : $text['en'];
            $template = $this->mailer->createEMailTemplate('idregister.DeviceNotice', []);
            $template->setSubject($pick($subject));
            $template->addHeader();
            $template->addHeading($ro ? 'Salut, '.$user->getDisplayName() : 'Hello, '.$user->getDisplayName());
            $template->addBodyText($pick($body));
            $template->addFooter($ro
                ? 'Telefoanele legate de cont se văd în Setări personale › Informații personale.'
                : 'The phones tied to your account are in Personal settings › Personal info.');
            $message = $this->mailer->createMessage();
            $message->setTo([$address => $user->getDisplayName()]);
            $message->useTemplate($template);
            $this->mailer->send($message);
        } catch (\Throwable $e) {
            $this->logger->warning('idregister: the letter about a paired phone could not be sent', ['exception' => $e]);
        }
    }

    /** A notice to the account itself; never worth failing the thing it reports. */
    private function tell(string $uid, string $subject, string $object, array $parameters): void
    {
        try {
            $notification = $this->notifications->createNotification();
            $notification->setApp(\OCA\IdRegister\AppInfo\Application::APP_ID)
                ->setUser($uid)
                ->setObject('device', $object)
                ->setDateTime(new \DateTime())
                ->setSubject($subject, $parameters)
            ;
            $this->notifications->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->warning('idregister: the notice about a paired phone could not be sent', ['exception' => $e]);
        }
    }

    /**
     * Rows nobody will come back for, dropped the moment they are past their end: while they live
     * they hold the address and the browser a sign-in was asked from.
     */
    public function forgetExpired(): void
    {
        foreach (['idregister_pairing', 'idregister_authreq'] as $table) {
            $query = $this->db->getQueryBuilder();
            $query->delete($table)
                ->where($query->expr()->lt('expires', $query->createNamedParameter(time(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeStatement()
            ;
        }
    }

    private function drop(string $requestId): void
    {
        $query = $this->db->getQueryBuilder();
        $query->delete('idregister_authreq')
            ->where($query->expr()->eq('request_id', $query->createNamedParameter($requestId)))
            ->executeStatement()
        ;
    }

    // --------------------------------------------------------------- private

    /**
     * ECDSA over the P-256 curve with SHA-256 — what an Android key store can make and use
     * behind a fingerprint on every phone that matters. The key arrives as the X.509 blob
     * Android hands out, in base64.
     */
    private function verify(string $publicKey, string $message, string $signature): bool
    {
        $der = base64_decode($signature, true);
        $raw = base64_decode($publicKey, true);
        if (false === $der || false === $raw || '' === $der || '' === $raw) {
            return false;
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($raw), 64, "\n").'-----END PUBLIC KEY-----'."\n";
        $key = openssl_pkey_get_public($pem);
        if (false === $key) {
            return false;
        }
        $details = openssl_pkey_get_details($key);
        if (!\is_array($details) || OPENSSL_KEYTYPE_EC !== ($details['type'] ?? -1)) {
            return false;
        }

        return 1 === openssl_verify($message, $der, $key, OPENSSL_ALGO_SHA256);
    }

    private function requireEnabled(): void
    {
        if (!$this->enabled()) {
            throw new \InvalidArgumentException($this->l->t('Signing in with a phone is not available here.'));
        }
    }

    /** @return array<string, mixed>|null */
    private function pairingRow(string $token): ?array
    {
        if ('' === $token) {
            return null;
        }
        $query = $this->db->getQueryBuilder();
        $query->select('*')->from('idregister_pairing')
            ->where($query->expr()->eq('token', $query->createNamedParameter($token)))
            ->andWhere($query->expr()->gt('expires', $query->createNamedParameter(time(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
        ;
        $row = $query->executeQuery()->fetch();

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    private function authRow(string $requestId): ?array
    {
        if ('' === $requestId) {
            return null;
        }
        $query = $this->db->getQueryBuilder();
        $query->select('*')->from('idregister_authreq')
            ->where($query->expr()->eq('request_id', $query->createNamedParameter($requestId)))
            ->andWhere($query->expr()->gt('expires', $query->createNamedParameter(time(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
        ;
        $row = $query->executeQuery()->fetch();

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    private function device(string $deviceId): ?array
    {
        if ('' === $deviceId) {
            return null;
        }
        $query = $this->db->getQueryBuilder();
        $query->select('*')->from('idregister_device')
            ->where($query->expr()->eq('device_id', $query->createNamedParameter($deviceId)))
        ;
        $row = $query->executeQuery()->fetch();

        return $row ?: null;
    }
}
