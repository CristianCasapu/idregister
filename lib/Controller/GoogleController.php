<?php

declare(strict_types=1);

namespace OCA\IdRegister\Controller;

use OCA\IdRegister\AppInfo\Application;
use OCA\IdRegister\Service\Google;
use OCA\IdRegister\Service\SignIn;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Linking a Google account to an account here, and signing in with it afterwards.
 *
 * Nothing here creates an account: an unknown Google account is sent to the registration.
 */
#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
final class GoogleController extends Controller
{
    /**
     * Where the outcome of a linking waits for the personal settings page. It is kept with the
     * account and not in the session, because the settings page is rendered by a controller that
     * closes the session before it runs.
     */
    public const MESSAGE_KEY = 'googleMessage';

    public function __construct(
        IRequest $request,
        private Google $google,
        private SignIn $signIn,
        private IUserSession $userSession,
        private IUserManager $userManager,
        private IConfig $config,
        private IURLGenerator $urlGenerator,
        private IL10N $l,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /** The "Continue with Google" button under the sign-in form. */
    #[PublicPage]
    #[NoCSRFRequired]
    #[UseSession]
    #[AnonRateLimit(limit: 30, period: 900)]
    public function login(string $redirect_url = ''): Response
    {
        if (null !== $this->userSession->getUser()) {
            return new RedirectResponse($this->urlGenerator->linkToDefaultPageUrl());
        }

        try {
            return new RedirectResponse($this->google->begin(Google::ACTION_LOGIN, '', $this->safeRedirect($redirect_url)));
        } catch (\InvalidArgumentException $e) {
            return $this->failed($e->getMessage());
        }
    }

    /** Start linking: the personal settings page asks for the address to send the browser to. */
    #[NoAdminRequired]
    #[UseSession]
    public function start(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return new JSONResponse(['ok' => false, 'message' => 'not signed in'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            return new JSONResponse(['ok' => true, 'url' => $this->google->begin(Google::ACTION_LINK, $user->getUID())]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['ok' => false, 'message' => $e->getMessage()]);
        }
    }

    #[NoAdminRequired]
    public function unlink(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return new JSONResponse(['ok' => false, 'message' => 'not signed in'], Http::STATUS_UNAUTHORIZED);
        }
        $this->google->unlink($user->getUID());

        return new JSONResponse(['ok' => true, 'linked' => false]);
    }

    /** Google sends the browser back here, both for a linking and for a sign-in. */
    #[PublicPage]
    #[NoCSRFRequired]
    #[UseSession]
    #[BruteForceProtection(action: 'idregisterGoogle')]
    public function callback(string $code = '', string $state = '', string $error = ''): Response
    {
        // which flow this is has to be known before anything else, so that a linking that goes
        // wrong says so on the settings page the person came from, not on a sign-in page
        try {
            $flow = $this->google->takeFlow($state);
        } catch (\InvalidArgumentException $e) {
            $response = $this->failed($e->getMessage());
            $response->throttle(['action' => 'idregisterGoogle']);

            return $response;
        }

        if ('' !== $error) {
            // the person pressed "cancel" on Google's page, or Google refused the application
            return $this->gaveUp($flow, 'access_denied' === $error
                ? $this->l->t('The sign-in with Google was cancelled.')
                : $this->l->t('Google refused the sign-in: %s', [$error]));
        }

        try {
            $claims = $this->google->identify($flow, $code);
        } catch (\InvalidArgumentException $e) {
            return $this->gaveUp($flow, $e->getMessage());
        }

        $result = $flow + $claims;

        return Google::ACTION_LINK === $flow['action'] ? $this->finishLink($result) : $this->finishLogin($result);
    }

    /** A flow that did not get as far as an identity: say so where the person started it. */
    private function gaveUp(array $flow, string $message): Response
    {
        $user = $this->userSession->getUser();
        if (Google::ACTION_LINK === $flow['action'] && null !== $user && $user->getUID() === $flow['uid']) {
            $this->remember($user->getUID(), false, $message);

            return new RedirectResponse($this->personalSettings());
        }
        $response = $this->failed($message);
        $response->throttle(['action' => 'idregisterGoogle']);

        return $response;
    }

    /**
     * @param array{uid:string, sub:string, email:string, name:string, redirect:string} $result
     */
    private function finishLink(array $result): Response
    {
        $user = $this->userSession->getUser();
        if (null === $user || $user->getUID() !== $result['uid']) {
            return $this->failed($this->l->t('You are not signed in any more, so nothing was linked. Sign in and try again.'));
        }

        try {
            $this->google->link($user, $result['sub'], $result['email']);
            $this->remember($user->getUID(), true, $this->l->t('Your Google account is linked. You can sign in with it from now on.'));
        } catch (\InvalidArgumentException $e) {
            $this->remember($user->getUID(), false, $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('idregister: the Google account could not be linked', ['exception' => $e]);
            $this->remember($user->getUID(), false, $this->l->t('Something went wrong. Please try again.'));
        }

        return new RedirectResponse($this->personalSettings());
    }

    /**
     * @param array{uid:string, sub:string, email:string, name:string, redirect:string} $result
     */
    private function finishLogin(array $result): Response
    {
        $uid = $this->google->uidFor($result['sub']);
        $user = null === $uid ? null : $this->userManager->get($uid);
        if (null === $user) {
            return $this->failed(
                $this->l->t('This Google account is not linked to any account here. Sign in with your user name and password, then link it in your personal settings.'),
                true,
            );
        }
        if (!$user->isEnabled()) {
            return $this->failed($this->l->t('This account is waiting for an administrator.'));
        }

        try {
            $url = $this->signIn->complete($user, $this->request, $this->safeRedirect($result['redirect']));
        } catch (\Throwable $e) {
            $this->logger->error('idregister: the Google sign-in of '.$user->getUID().' failed', ['exception' => $e]);

            return $this->failed($this->l->t('You could not be signed in. Please try again.'));
        }

        return new RedirectResponse($url);
    }

    private function personalSettings(): string
    {
        return $this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'personal-info']).'#idregister-personal';
    }

    private function remember(string $uid, bool $ok, string $message): void
    {
        $this->config->setUserValue($uid, Application::APP_ID, self::MESSAGE_KEY, (string) json_encode(['ok' => $ok, 'message' => $message]));
    }

    /** A page that says what went wrong, with the way back. */
    private function failed(string $message, bool $offerRegistration = false): TemplateResponse
    {
        return new TemplateResponse(Application::APP_ID, 'google', [
            'message' => $message,
            'loginUrl' => $this->urlGenerator->linkToRouteAbsolute('core.login.showLoginForm'),
            'registerUrl' => $offerRegistration ? $this->urlGenerator->linkToRoute('idregister.page.index') : '',
        ], TemplateResponse::RENDER_AS_GUEST);
    }

    /** Only a place on this server, and never one with a user name in it — the rule the sign-in form uses. */
    private function safeRedirect(string $url): string
    {
        if ('' === trim($url)) {
            return '';
        }
        $absolute = $this->urlGenerator->getAbsoluteURL(urldecode($url));

        return str_contains($absolute, '@') ? '' : $absolute;
    }
}
