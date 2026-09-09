<?php

declare(strict_types=1);

namespace OCA\IdRegister\Service;

use OCA\IdRegister\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * "Continue with Google": an account that already exists here can be tied to a Google account and
 * from then on signs in with it, without a password.
 *
 * Google never creates an account: registration stays with the identity card. The link is keyed on
 * Google's `sub`, the permanent identifier of the Google account — the e-mail address of a Google
 * account can change, `sub` cannot. Linking is only allowed when the Google address is verified by
 * Google and is the very address already confirmed here, so a Google account can never be attached
 * to somebody else's account.
 *
 * The identity token comes back from Google's token endpoint over TLS, on a connection this server
 * opened itself, so its signature is not checked separately: OpenID Connect Core 3.1.3.7 allows the
 * TLS server validation to stand in for it when the token is fetched by direct communication.
 */
final class Google
{
    public const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    public const ACTION_LINK = 'link';
    public const ACTION_LOGIN = 'login';

    /** Both spellings are used by Google. */
    private const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    /** How long the browser has to come back from Google. */
    private const FLOW_TTL = 900;

    private const SESSION_KEY = 'idregister_google_flow';

    public function __construct(
        private IAppConfig $config,
        private IDBConnection $db,
        private ISession $session,
        private IClientService $clientService,
        private IURLGenerator $urlGenerator,
        private ISecureRandom $random,
        private Settings $settings,
        private INotificationManager $notifications,
        private IL10N $l,
        private LoggerInterface $logger,
    ) {}

    /** Is signing in with Google switched on and set up? */
    public function enabled(): bool
    {
        return (bool) $this->settings->get('googleEnabled') && $this->configured();
    }

    public function configured(): bool
    {
        return '' !== $this->clientId() && '' !== $this->clientSecret();
    }

    /**
     * Google's own addresses. They can be pointed somewhere else through the app configuration
     * (`occ config:app:set idregister googleAuthEndpoint`), which is only meant for testing this
     * flow against a stand-in for Google.
     */
    private function endpoint(string $key, string $default): string
    {
        $value = trim($this->config->getValueString(Application::APP_ID, $key, $default));

        return '' === $value ? $default : $value;
    }

    public function clientId(): string
    {
        return trim((string) $this->settings->get('googleClientId'));
    }

    public function secretSet(): bool
    {
        return '' !== $this->clientSecret();
    }

    /** The secret is written but never read back to a browser. */
    public function setClientSecret(string $secret): void
    {
        $this->config->setValueString(Application::APP_ID, 'googleClientSecret', trim($secret), sensitive: true);
    }

    /** What has to be pasted into the Google console, character for character. */
    public function redirectUri(): string
    {
        return $this->urlGenerator->linkToRouteAbsolute('idregister.google.callback');
    }

    /**
     * Start a flow: remember what it is for, and say where to send the browser.
     *
     * @param self::ACTION_* $action
     */
    public function begin(string $action, string $uid = '', string $redirectUrl = ''): string
    {
        if (!$this->enabled()) {
            throw new \InvalidArgumentException($this->l->t('Signing in with Google is not available here.'));
        }
        $state = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
        $nonce = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
        $verifier = $this->random->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
        $this->session->set(self::SESSION_KEY, json_encode([
            'state' => $state,
            'nonce' => $nonce,
            'verifier' => $verifier,
            'action' => $action,
            'uid' => $uid,
            'redirect' => $redirectUrl,
            'until' => time() + self::FLOW_TTL,
        ]));

        $params = [
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            // always show the chooser: the address has to be the one confirmed here
            'prompt' => 'select_account',
        ];

        return $this->endpoint('googleAuthEndpoint', self::AUTH_ENDPOINT).'?'.http_build_query($params);
    }

    /**
     * The browser is back from Google: take the flow that was started, checking that it is the
     * same one and that it has not been used already.
     *
     * @return array{action:string, uid:string, redirect:string, nonce:string, verifier:string}
     */
    public function takeFlow(string $state): array
    {
        $raw = (string) $this->session->get(self::SESSION_KEY);
        $this->session->remove(self::SESSION_KEY);
        $flow = json_decode($raw, true);
        if (!\is_array($flow) || !isset($flow['state'], $flow['nonce'], $flow['verifier'])) {
            throw new \InvalidArgumentException($this->l->t('This sign-in has expired. Please start again.'));
        }
        if (time() > (int) ($flow['until'] ?? 0)) {
            throw new \InvalidArgumentException($this->l->t('This sign-in has expired. Please start again.'));
        }
        if ('' === $state || !hash_equals((string) $flow['state'], $state)) {
            throw new \InvalidArgumentException($this->l->t('This sign-in could not be checked. Please start again.'));
        }

        return [
            'action' => (string) ($flow['action'] ?? self::ACTION_LOGIN),
            'uid' => (string) ($flow['uid'] ?? ''),
            'redirect' => (string) ($flow['redirect'] ?? ''),
            'nonce' => (string) $flow['nonce'],
            'verifier' => (string) $flow['verifier'],
        ];
    }

    /**
     * Trade the code Google sent for the identity token, and say who it is about.
     *
     * @param array{nonce:string, verifier:string} $flow
     *
     * @return array{sub:string, email:string, name:string}
     */
    public function identify(array $flow, string $code): array
    {
        if ('' === $code) {
            throw new \InvalidArgumentException($this->l->t('Google did not send anything back. Please try again.'));
        }

        return $this->exchange($code, $flow['verifier'], $flow['nonce']);
    }

    /**
     * Tie this Google account to this account here. Only the address already confirmed here
     * is accepted, and only when Google says it verified it.
     */
    public function link(IUser $user, string $sub, string $email): void
    {
        $accountEmail = mb_strtolower(trim((string) $user->getSystemEMailAddress()));
        if ('' === $accountEmail) {
            throw new \InvalidArgumentException($this->l->t('Confirm your e-mail address here first, then link your Google account.'));
        }
        if ($accountEmail !== $email) {
            throw new \InvalidArgumentException($this->l->t('This Google account uses %1$s, but your account here uses %2$s. Sign in to Google with %2$s, or ask an administrator.', [$email, $accountEmail]));
        }
        $owner = $this->uidFor($sub);
        if (null !== $owner && $owner !== $user->getUID()) {
            throw new \InvalidArgumentException($this->l->t('This Google account is already linked to another account here.'));
        }
        if (null !== $owner) {
            return; // already linked to this very account
        }
        $this->unlink($user->getUID());
        $insert = $this->db->getQueryBuilder();
        $insert->insert('idregister_google')->values([
            'uid' => $insert->createNamedParameter($user->getUID()),
            'google_sub' => $insert->createNamedParameter($sub),
            'google_email' => $insert->createNamedParameter($email),
            'linked_at' => $insert->createNamedParameter(time(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        $this->logger->info('idregister: '.$user->getUID().' linked a Google account');
        try {
            $notification = $this->notifications->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($user->getUID())
                ->setObject('google', $sub)
                ->setDateTime(new \DateTime())
                ->setSubject('google_linked', ['email' => $email])
            ;
            $this->notifications->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->warning('idregister: the notice about the linked Google account could not be sent', ['exception' => $e]);
        }
    }

    public function unlink(string $uid): void
    {
        $query = $this->db->getQueryBuilder();
        $query->delete('idregister_google')
            ->where($query->expr()->eq('uid', $query->createNamedParameter($uid)))
            ->executeStatement()
        ;
    }

    /** @return array{email:string, linked_at:int}|null */
    public function linkedRow(string $uid): ?array
    {
        $query = $this->db->getQueryBuilder();
        $query->select('google_email', 'linked_at')->from('idregister_google')
            ->where($query->expr()->eq('uid', $query->createNamedParameter($uid)))
        ;
        $row = $query->executeQuery()->fetch();

        return $row ? ['email' => (string) $row['google_email'], 'linked_at' => (int) $row['linked_at']] : null;
    }

    public function uidFor(string $sub): ?string
    {
        $query = $this->db->getQueryBuilder();
        $query->select('uid')->from('idregister_google')
            ->where($query->expr()->eq('google_sub', $query->createNamedParameter($sub)))
        ;
        $row = $query->executeQuery()->fetch();

        return $row ? (string) $row['uid'] : null;
    }

    /**
     * Trade the code for the identity token and read the claims out of it.
     *
     * @return array{sub:string, email:string, name:string}
     */
    private function exchange(string $code, string $verifier, string $nonce): array
    {
        try {
            $response = $this->clientService->newClient()->post($this->endpoint('googleTokenEndpoint', self::TOKEN_ENDPOINT), [
                'body' => [
                    'code' => $code,
                    'client_id' => $this->clientId(),
                    'client_secret' => $this->clientSecret(),
                    'redirect_uri' => $this->redirectUri(),
                    'grant_type' => 'authorization_code',
                    'code_verifier' => $verifier,
                ],
                'timeout' => 15,
            ]);
            $payload = json_decode((string) $response->getBody(), true);
        } catch (\Throwable $e) {
            $this->logger->error('idregister: the Google token endpoint could not be reached', ['exception' => $e]);

            throw new \InvalidArgumentException($this->l->t('Google could not be reached. Please try again.'));
        }
        if (!\is_array($payload) || !\is_string($payload['id_token'] ?? null)) {
            $this->logger->error('idregister: Google sent no identity token', ['payload' => \is_array($payload) ? array_keys($payload) : 'none']);

            throw new \InvalidArgumentException($this->l->t('Google did not confirm who you are. Please try again.'));
        }

        $parts = explode('.', $payload['id_token']);
        $claims = 3 === \count($parts) ? json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
        if (!\is_array($claims)) {
            throw new \InvalidArgumentException($this->l->t('Google did not confirm who you are. Please try again.'));
        }
        if (!\in_array((string) ($claims['iss'] ?? ''), self::ISSUERS, true)) {
            throw new \InvalidArgumentException($this->l->t('Google did not confirm who you are. Please try again.'));
        }
        $audience = $claims['aud'] ?? '';
        $audience = \is_array($audience) ? (string) reset($audience) : (string) $audience;
        if (!hash_equals($this->clientId(), $audience)) {
            throw new \InvalidArgumentException($this->l->t('Google answered for a different application. Check the client ID in the administration settings.'));
        }
        if (time() >= (int) ($claims['exp'] ?? 0)) {
            throw new \InvalidArgumentException($this->l->t('This sign-in has expired. Please start again.'));
        }
        if (!hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
            throw new \InvalidArgumentException($this->l->t('This sign-in could not be checked. Please start again.'));
        }
        $sub = trim((string) ($claims['sub'] ?? ''));
        if ('' === $sub) {
            throw new \InvalidArgumentException($this->l->t('Google did not confirm who you are. Please try again.'));
        }
        if (true !== ($claims['email_verified'] ?? false) && 'true' !== ($claims['email_verified'] ?? false)) {
            throw new \InvalidArgumentException($this->l->t('Google has not verified the e-mail address of this Google account.'));
        }

        return [
            'sub' => $sub,
            'email' => mb_strtolower(trim((string) ($claims['email'] ?? ''))),
            'name' => trim((string) ($claims['name'] ?? '')),
        ];
    }

    private function clientSecret(): string
    {
        return $this->config->getValueString(Application::APP_ID, 'googleClientSecret', '', lazy: false);
    }
}
