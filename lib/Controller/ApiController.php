<?php

declare(strict_types=1);

namespace OCA\IdRegister\Controller;

use OCA\IdRegister\AppInfo\Application;
use OCA\IdRegister\Service\Ocr;
use OCA\IdRegister\Service\Registration;
use OCA\IdRegister\Service\Settings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The public part of the registration. Everything here is rate limited per address.
 */
#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class ApiController extends Controller
{
    public const MAX_UPLOAD = 12 * 1024 * 1024;

    public function __construct(
        IRequest $request,
        private Ocr $ocr,
        private Registration $registration,
        private Settings $settings,
        private IL10N $l,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Read an identity card. The picture is not stored.
     */
    #[PublicPage]
    #[AnonRateLimit(limit: 10, period: 3600)]
    public function scan(): JSONResponse
    {
        if (!$this->settings->get('registrationOpen')) {
            return $this->error($this->l->t('Registration is currently closed.'));
        }
        $file = $this->request->getUploadedFile('image');
        if (null === $file || !isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return $this->error($this->l->t('No picture was received.'));
        }
        if (($file['size'] ?? 0) > self::MAX_UPLOAD) {
            return $this->error($this->l->t('The picture is too large (maximum 12 MB).'));
        }
        $data = file_get_contents($file['tmp_name']);
        @unlink($file['tmp_name']);
        if (false === $data || '' === $data) {
            return $this->error($this->l->t('The picture could not be read.'));
        }

        try {
            $card = $this->ocr->readIdCard($data);
        } catch (\Throwable $e) {
            $this->logger->error('idregister: the card could not be read', ['exception' => $e]);

            return $this->error($this->l->t('The identity card could not be read. Try again with more light and the whole card in the frame.'));
        }
        unset($data);

        $enough = $card['confidence'] >= (float) $this->settings->get('minConfidence')
            && '' !== $card['surname'] && '' !== $card['givenNames'];

        return new JSONResponse([
            'ok' => $enough,
            'surname' => $card['surname'],
            'givenNames' => $card['givenNames'],
            'confidence' => $card['confidence'],
            // the personal number goes back to the browser only to be sent again on submit;
            // the server never stores it in clear
            'cnp' => $card['cnpSure'] ? $card['cnp'] : '',
            'message' => $enough ? '' : $this->l->t('The identity card could not be read. Try again with more light and the whole card in the frame.'),
        ], Http::STATUS_OK);
    }

    #[PublicPage]
    #[AnonRateLimit(limit: 5, period: 3600)]
    public function register(
        string $surname = '',
        string $givenNames = '',
        string $cnp = '',
        string $email = '',
        string $phone = '',
        string $password = '',
        string $language = 'en',
        bool $terms = false,
    ): JSONResponse {
        if (!$terms) {
            return $this->error($this->l->t('Please accept how your data is used.'));
        }

        try {
            $result = $this->registration->start(
                ['surname' => $surname, 'givenNames' => $givenNames, 'cnp' => $cnp],
                $email,
                $phone,
                $password,
                $language,
            );

            return new JSONResponse(['ok' => true] + $result, Http::STATUS_OK);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('idregister: registration failed', ['exception' => $e]);

            return $this->error($this->l->t('Something went wrong. Please try again.'));
        }
    }

    #[PublicPage]
    #[AnonRateLimit(limit: 20, period: 3600)]
    public function verifyCode(string $token = '', string $code = ''): JSONResponse
    {
        try {
            return new JSONResponse(['ok' => true] + $this->registration->confirmWithCode($token, $code), Http::STATUS_OK);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('idregister: confirmation failed', ['exception' => $e]);

            return $this->error($this->l->t('Something went wrong. Please try again.'));
        }
    }

    #[PublicPage]
    #[AnonRateLimit(limit: 5, period: 3600)]
    public function resend(string $token = '', string $language = 'en'): JSONResponse
    {
        try {
            $this->registration->resend($token, $language);

            return new JSONResponse(['ok' => true], Http::STATUS_OK);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            return $this->error($this->l->t('Something went wrong. Please try again.'));
        }
    }

    private function error(string $message): JSONResponse
    {
        return new JSONResponse(['ok' => false, 'message' => $message], Http::STATUS_OK);
    }
}
