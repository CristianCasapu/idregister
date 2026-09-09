<?php

declare(strict_types=1);

namespace OCA\IdRegister\Controller;

use OCA\IdRegister\AppInfo\Application;
use OCA\IdRegister\Service\Devices;
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
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Pairing a phone with an account, and signing in with it afterwards.
 *
 * The endpoints the phone talks to are open — it has no session here; what stands in for one is
 * the signature it makes with the key that was paired.
 */
#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
final class DeviceController extends Controller
{
    public function __construct(
        IRequest $request,
        private Devices $devices,
        private SignIn $signIn,
        private IUserSession $userSession,
        private IInitialState $initialState,
        private IURLGenerator $urlGenerator,
        private IL10N $l,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    // ------------------------------------------------------- the account side

    #[NoAdminRequired]
    #[UserRateLimit(limit: 10, period: 600)]
    public function pair(string $password = ''): JSONResponse
    {
        // the token is in the code the phone reads anyway; the page needs it to ask how it went
        return $this->mine(fn ($user) => $this->devices->startPairing($user, $password));
    }

    #[NoAdminRequired]
    public function pairStatus(string $token = ''): JSONResponse
    {
        return $this->mine(fn ($user) => $this->devices->pairingState($user->getUID(), $token)
            + ['devices' => $this->devices->devices($user->getUID())]);
    }

    #[NoAdminRequired]
    public function devices(): JSONResponse
    {
        return $this->mine(fn ($user) => ['devices' => $this->devices->devices($user->getUID())]);
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 10, period: 600)]
    public function forget(string $deviceId = '', string $password = ''): JSONResponse
    {
        return $this->mine(function ($user) use ($deviceId, $password) {
            $this->devices->forget($user, $deviceId, $password);

            return ['devices' => $this->devices->devices($user->getUID())];
        });
    }

    // --------------------------------------------------------- the phone side

    /** The phone read the pairing code and sends the public half of its new key. */
    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 30, period: 600)]
    #[BruteForceProtection(action: 'idregisterPair')]
    public function pairComplete(string $token = '', string $publicKey = '', string $name = '', string $signature = ''): JSONResponse
    {
        return $this->phone(fn () => $this->devices->completePairing($token, $publicKey, $name, $signature), 'idregisterPair');
    }

    /** The phone unties itself. It signs for it, so a stray hand cannot do it. */
    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 30, period: 600)]
    #[BruteForceProtection(action: 'idregisterForget')]
    public function forgetFromPhone(string $deviceId = '', int $timestamp = 0, string $signature = ''): JSONResponse
    {
        return $this->phone(fn () => $this->devices->forgetSigned($deviceId, $timestamp, $signature), 'idregisterForget');
    }

    /** What is being asked, so the phone can show it before anybody puts a finger on the reader. */
    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 120, period: 600)]
    public function describe(string $requestId = '', string $nonce = ''): JSONResponse
    {
        return $this->phone(fn () => $this->devices->describe($requestId, $nonce));
    }

    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 60, period: 600)]
    #[BruteForceProtection(action: 'idregisterApprove')]
    public function approve(string $requestId = '', string $deviceId = '', string $number = '', int $timestamp = 0, string $signature = ''): JSONResponse
    {
        return $this->phone(function () use ($requestId, $deviceId, $number, $timestamp, $signature) {
            $this->devices->approve($requestId, $deviceId, $number, $timestamp, $signature);

            return ['approved' => true];
        }, 'idregisterApprove');
    }

    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 60, period: 600)]
    public function deny(string $requestId = '', string $nonce = ''): JSONResponse
    {
        $this->devices->deny($requestId, $nonce);

        return new JSONResponse(['ok' => true]);
    }

    // -------------------------------------------------------- the browser side

    /** The page with the QR code, reached from "Sign in with your phone". */
    #[PublicPage]
    #[NoCSRFRequired]
    public function page(string $redirect_url = ''): Response
    {
        if (null !== $this->userSession->getUser()) {
            return new RedirectResponse($this->urlGenerator->linkToDefaultPageUrl());
        }
        $this->initialState->provideInitialState('phoneRedirect', $redirect_url);
        $this->initialState->provideInitialState('loginUrl', $this->urlGenerator->linkToRouteAbsolute('core.login.showLoginForm'));
        $this->initialState->provideInitialState('registerUrl', $this->urlGenerator->linkToRoute('idregister.page.index'));
        $this->initialState->provideInitialState('phoneEnabled', $this->devices->enabled());

        return new TemplateResponse(Application::APP_ID, 'phone', [], TemplateResponse::RENDER_AS_GUEST);
    }

    #[PublicPage]
    #[AnonRateLimit(limit: 30, period: 600)]
    public function loginStart(string $redirect = ''): JSONResponse
    {
        try {
            $start = $this->devices->startLogin($this->request, $redirect);

            return new JSONResponse(['ok' => true] + $start);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['ok' => false, 'message' => $e->getMessage()]);
        }
    }

    /** The browser asks whether the phone has answered; when it has, this is where it is signed in. */
    #[PublicPage]
    #[UseSession]
    #[AnonRateLimit(limit: 240, period: 600)]
    public function loginPoll(string $requestId = '', string $secret = ''): JSONResponse
    {
        $result = $this->devices->collect($requestId, $secret);
        $user = $result['user'];
        if (Devices::STATE_APPROVED !== $result['state'] || null === $user) {
            return new JSONResponse(['ok' => true, 'state' => $result['state']]);
        }

        try {
            $url = $this->signIn->complete($user, $this->request, $this->safeRedirect($result['redirect']));
        } catch (\Throwable $e) {
            $this->logger->error('idregister: the phone sign-in of '.$user->getUID().' failed', ['exception' => $e]);

            return new JSONResponse(['ok' => false, 'state' => 'failed', 'message' => $this->l->t('You could not be signed in. Please try again.')]);
        }

        return new JSONResponse(['ok' => true, 'state' => Devices::STATE_APPROVED, 'url' => $url]);
    }

    // ----------------------------------------------------------------- helpers

    private function mine(callable $action): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return new JSONResponse(['ok' => false, 'message' => 'not signed in'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            return new JSONResponse(['ok' => true] + $action($user));
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['ok' => false, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('idregister: a paired phone could not be handled', ['exception' => $e]);

            return new JSONResponse(['ok' => false, 'message' => $this->l->t('Something went wrong. Please try again.')]);
        }
    }

    private function phone(callable $action, string $throttleAction = ''): JSONResponse
    {
        try {
            return new JSONResponse(['ok' => true] + $action());
        } catch (\InvalidArgumentException $e) {
            $response = new JSONResponse(['ok' => false, 'message' => $e->getMessage()]);
            if ('' !== $throttleAction) {
                $response->throttle(['action' => $throttleAction]);
            }

            return $response;
        } catch (\Throwable $e) {
            $this->logger->error('idregister: a phone request could not be handled', ['exception' => $e]);

            return new JSONResponse(['ok' => false, 'message' => $this->l->t('Something went wrong. Please try again.')]);
        }
    }

    /** Only a place on this server — the rule the sign-in form uses. */
    private function safeRedirect(string $url): string
    {
        if ('' === trim($url)) {
            return '';
        }
        $absolute = $this->urlGenerator->getAbsoluteURL(urldecode($url));

        return str_contains($absolute, '@') ? '' : $absolute;
    }
}
