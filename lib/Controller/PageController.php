<?php

declare(strict_types=1);

namespace OCA\IdRegister\Controller;

use OCA\IdRegister\AppInfo\Application;
use OCA\IdRegister\Service\Device;
use OCA\IdRegister\Service\FaceMatch;
use OCA\IdRegister\Service\Handoff;
use OCA\IdRegister\Service\Ocr;
use OCA\IdRegister\Service\Registration;
use OCA\IdRegister\Service\Settings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;

#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class PageController extends Controller
{
    public function __construct(
        IRequest $request,
        private IInitialState $initialState,
        private Settings $settings,
        private Registration $registration,
        private Handoff $handoff,
        private FaceMatch $faceMatch,
        private IURLGenerator $urlGenerator,
        private \OCP\IUserSession $userSession,
        private IL10N $l,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function index(string $s = ''): Response
    {
        // someone who is already signed in has nothing to do here
        if (null !== $this->userSession->getUser()) {
            return new RedirectResponse($this->urlGenerator->linkToDefaultPageUrl());
        }

        $mobile = Device::isMobile((string) $this->request->getHeader('User-Agent'));
        $handoffToken = trim($s);
        if ('' !== $handoffToken && null !== $this->handoff->get($handoffToken)) {
            $this->handoff->advance($handoffToken, Handoff::STATE_OPENED);
        } else {
            $handoffToken = '';
        }

        $this->initialState->provideInitialState('mobile', $mobile);
        $this->initialState->provideInitialState('mobileOnly', (bool) $this->settings->get('mobileOnly'));
        $this->initialState->provideInitialState('handoff', $handoffToken);
        $this->initialState->provideInitialState('registrationOpen', (bool) $this->settings->get('registrationOpen'));
        $this->initialState->provideInitialState('requireApproval', (bool) $this->settings->get('requireApproval'));
        $this->initialState->provideInitialState('conditions', [
            'minPasswordLength' => max(8, (int) $this->settings->get('minPasswordLength')),
            'requirePhone' => (bool) $this->settings->get('requirePhone'),
            'minAge' => (int) $this->settings->get('minAge'),
            'termsUrl' => (string) $this->settings->get('termsUrl'),
            'requireSelfie' => (bool) $this->settings->get('requireSelfie') && $this->faceMatch->available(),
            'acceptIdCard' => (bool) $this->settings->get('acceptIdCard'),
            'acceptDrivingLicence' => (bool) $this->settings->get('acceptDrivingLicence'),
        ]);
        $this->initialState->provideInitialState('ocr', Ocr::status()['ok']);
        $this->initialState->provideInitialState('loginUrl', $this->urlGenerator->linkToRouteAbsolute('core.login.showLoginForm'));

        $response = new TemplateResponse(Application::APP_ID, 'index', [], TemplateResponse::RENDER_AS_GUEST);
        $policy = new \OCP\AppFramework\Http\ContentSecurityPolicy();
        $policy->addAllowedImageDomain('blob:');
        $response->setContentSecurityPolicy($policy);

        return $response;
    }

    /** The link in the confirmation e-mail. */
    #[PublicPage]
    #[NoCSRFRequired]
    public function verify(string $token): Response
    {
        if (null !== $this->userSession->getUser()) {
            return new RedirectResponse($this->urlGenerator->linkToDefaultPageUrl());
        }

        $error = '';
        $result = null;

        try {
            $result = $this->registration->confirmWithToken($token);
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (\Throwable $e) {
            $error = $this->l->t('Something went wrong. Please try again.');
        }

        $this->initialState->provideInitialState('verifyToken', '' === $error ? $token : '');
        $this->initialState->provideInitialState('verifyResult', $result);
        $this->initialState->provideInitialState('conditions', [
            'minPasswordLength' => max(8, (int) $this->settings->get('minPasswordLength')),
        ]);
        $this->initialState->provideInitialState('loginUrl', $this->urlGenerator->linkToRouteAbsolute('core.login.showLoginForm'));

        return new TemplateResponse(Application::APP_ID, 'verified', [
            'error' => $error,
            'result' => $result,
            'loginUrl' => $this->urlGenerator->linkToRouteAbsolute('core.login.showLoginForm'),
        ], TemplateResponse::RENDER_AS_GUEST);
    }
}
