<?php

declare(strict_types=1);

namespace OCA\IdRegister\Controller;

use OCA\IdRegister\AppInfo\Application;
use OCA\IdRegister\Service\Device;
use OCA\IdRegister\Service\DocumentReader;
use OCA\IdRegister\Service\FaceMatch;
use OCA\IdRegister\Service\Handoff;
use OCA\IdRegister\Service\IdCardParser;
use OCA\IdRegister\Service\Ocr;
use OCA\IdRegister\Service\Registration;
use OCA\IdRegister\Service\Settings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * The public part of the registration. Everything here is rate limited per address.
 */
#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class ApiController extends Controller
{
    public const MAX_UPLOAD = 12 * 1024 * 1024;
    public const SESSION_PREFIX = 'idregister_scan_';
    /** how long the browser may wait between reading the card and submitting the form */
    public const SCAN_TTL = 1800;

    public function __construct(
        IRequest $request,
        private Ocr $ocr,
        private Registration $registration,
        private Settings $settings,
        private FaceMatch $faceMatch,
        private Handoff $handoff,
        private ISession $session,
        private ISecureRandom $random,
        private IL10N $l,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Read an identity card. The picture is not stored.
     */
    #[UseSession]
    #[PublicPage]
    #[AnonRateLimit(limit: 10, period: 3600)]
    public function scan(string $handoff = ''): JSONResponse
    {
        if (!$this->settings->get('registrationOpen')) {
            return $this->error($this->l->t('Registration is currently closed.'));
        }
        if ($this->settings->get('mobileOnly') && !Device::isMobile((string) $this->request->getHeader('User-Agent'))) {
            return $this->error($this->l->t('Please continue on a phone or a tablet.'));
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
            $card = $this->ocr->readDocument($data);
        } catch (\Throwable $e) {
            $this->logger->error('idregister: the document could not be read', ['exception' => $e]);

            return $this->error($this->l->t('The identity card could not be read. Try again with more light and the whole card in the frame.'));
        }

        // which documents does the administrator take?
        $accepted = DocumentReader::TYPE_DRIVING_LICENCE === $card['type']
            ? (bool) $this->settings->get('acceptDrivingLicence')
            : (bool) $this->settings->get('acceptIdCard');
        if (!$accepted) {
            unset($data);

            return $this->error(DocumentReader::TYPE_DRIVING_LICENCE === $card['type']
                ? $this->l->t('A driving licence is not accepted here. Please use your identity card.')
                : $this->l->t('An identity card is not accepted here. Please use your driving licence.'));
        }

        $enough = $card['confidence'] >= (float) $this->settings->get('minConfidence')
            && '' !== $card['surname'] && '' !== $card['givenNames'];

        // The name is locked onto the account for good, so on an identity card it has to be
        // confirmed by both the printed text and the machine readable zone.
        if ($enough && DocumentReader::TYPE_ID_CARD === $card['type'] && $this->settings->get('requireConfirmedName') && !($card['nameSure'] ?? false)) {
            $enough = false;
            $message = $this->l->t('The name could not be read clearly. Take the picture again, straight, in good light and with the whole card in the frame.');
        }

        // the personal number only exists on an identity card
        if ($enough && DocumentReader::TYPE_ID_CARD === $card['type'] && $this->settings->get('requireValidCnp') && !$card['cnpSure']) {
            $enough = false;
            $message = $this->l->t('The personal number could not be read from the card. Take the picture again, straight and in good light.');
        }

        $age = self::ageFrom($card);
        $minAge = (int) $this->settings->get('minAge');
        if ($enough && $minAge > 0 && null !== $age && $age < $minAge) {
            return new JSONResponse([
                'ok' => false,
                'message' => $this->l->t('You have to be at least %d years old to register here.', [$minAge]),
            ], Http::STATUS_OK);
        }

        // What was read stays on the server: the browser only gets a handle to it, so the
        // personal number never travels and cannot be swapped for someone else's.
        $needsSelfie = (bool) $this->settings->get('requireSelfie') && $this->faceMatch->available();
        $scanId = $this->random->generate(24, ISecureRandom::CHAR_ALPHANUMERIC);
        $faceOnDocument = 0;
        if ($enough) {
            // Only the descriptor of the face printed on the document is kept, so the selfie can be
            // compared against it. The picture itself is dropped with $data below.
            $vector = [];
            if ($needsSelfie) {
                $face = $this->faceMatch->describe($data, 0.03);
                $vector = $face['vector'];
                $faceOnDocument = \count($vector) > 0 ? 1 : 0;
                if (0 === $faceOnDocument) {
                    unset($data);

                    return $this->error($this->l->t('The photo on the document could not be found. Take the picture again, with the whole document in the frame.'));
                }
            }
            $this->session->set(self::SESSION_PREFIX.$scanId, [
                'type' => $card['type'],
                'surname' => $card['surname'],
                'givenNames' => $card['givenNames'],
                'cnp' => $card['cnpSure'] ? $card['cnp'] : '',
                'birthDate' => $card['birthDate'],
                'age' => $age,
                'faceVector' => $vector,
                'selfie' => $needsSelfie ? '' : FaceMatch::VERDICT_MATCH,
                'time' => time(),
            ]);
            if ('' !== $handoff) {
                $this->handoff->advance($handoff, Handoff::STATE_DOCUMENT, trim($card['givenNames'].' '.$card['surname']));
            }
        }
        unset($data);

        return new JSONResponse([
            'ok' => $enough,
            'scanId' => $enough ? $scanId : '',
            'type' => $card['type'],
            'surname' => $card['surname'],
            'givenNames' => $card['givenNames'],
            'confidence' => $card['confidence'],
            'needsSelfie' => $needsSelfie,
            'message' => $enough ? '' : ($message ?? $this->l->t('The identity card could not be read. Try again with more light and the whole card in the frame.')),
        ], Http::STATUS_OK);
    }

    /**
     * The selfie, compared with the photo printed on the document.
     */
    #[UseSession]
    #[PublicPage]
    #[AnonRateLimit(limit: 15, period: 3600)]
    public function selfie(string $scanId = ''): JSONResponse
    {
        $card = $this->session->get(self::SESSION_PREFIX.$scanId);
        if (!\is_array($card) || (time() - (int) ($card['time'] ?? 0)) > self::SCAN_TTL) {
            return $this->error($this->l->t('Please read your identity card again.'));
        }
        $file = $this->request->getUploadedFile('image');
        if (null === $file || !isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return $this->error($this->l->t('No picture was received.'));
        }
        $data = file_get_contents($file['tmp_name']);
        @unlink($file['tmp_name']);
        if (false === $data || '' === $data) {
            return $this->error($this->l->t('The picture could not be read.'));
        }

        $result = $this->faceMatch->compare($card['faceVector'] ?? [], $data);
        unset($data);

        if (FaceMatch::VERDICT_NO_FACE === $result['verdict']) {
            return $this->error($this->l->t('No face was found in the selfie. Hold the phone in front of your face, in good light.'));
        }
        if (FaceMatch::VERDICT_DIFFERENT === $result['verdict']) {
            $this->logger->info('idregister: the selfie does not match the document (distance '.$result['distance'].')');

            return $this->error($this->l->t('The selfie does not match the photo on the document.'));
        }

        $card['selfie'] = $result['verdict'];
        $card['selfieDistance'] = $result['distance'];
        $this->session->set(self::SESSION_PREFIX.$scanId, $card);

        return new JSONResponse([
            'ok' => true,
            'verdict' => $result['verdict'],
            'review' => FaceMatch::VERDICT_REVIEW === $result['verdict'],
        ], Http::STATUS_OK);
    }

    #[UseSession]
    #[PublicPage]
    #[AnonRateLimit(limit: 5, period: 3600)]
    public function register(
        string $scanId = '',
        string $handoff = '',
        string $email = '',
        string $phone = '',
        string $password = '',
        string $language = 'en',
        bool $terms = false,
    ): JSONResponse {
        if (!$terms) {
            return $this->error($this->l->t('Please accept how your data is used.'));
        }
        $card = $this->session->get(self::SESSION_PREFIX.$scanId);
        if (!\is_array($card) || (time() - (int) ($card['time'] ?? 0)) > self::SCAN_TTL) {
            return $this->error($this->l->t('Please read your identity card again.'));
        }
        if ((bool) $this->settings->get('requireSelfie') && $this->faceMatch->available() && '' === (string) ($card['selfie'] ?? '')) {
            return $this->error($this->l->t('Please take the selfie first.'));
        }

        try {
            $result = $this->registration->start(
                [
                    'surname' => (string) $card['surname'],
                    'givenNames' => (string) $card['givenNames'],
                    'cnp' => (string) $card['cnp'],
                    'type' => (string) ($card['type'] ?? DocumentReader::TYPE_ID_CARD),
                    'birthDate' => (string) ($card['birthDate'] ?? ''),
                    'needsReview' => FaceMatch::VERDICT_REVIEW === ($card['selfie'] ?? ''),
                    'selfieDistance' => (float) ($card['selfieDistance'] ?? 0),
                ],
                $email,
                $phone,
                $password,
                $language,
            );
            $this->session->remove(self::SESSION_PREFIX.$scanId);
            if ('' !== $handoff) {
                $this->handoff->advance($handoff, Handoff::STATE_REGISTERED, trim((string) $card['givenNames'].' '.(string) $card['surname']));
            }

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
    public function verifyCode(string $token = '', string $code = '', string $handoff = ''): JSONResponse
    {
        try {
            $result = $this->registration->confirmWithCode($token, $code);
            if ('' !== $handoff) {
                $this->handoff->advance($handoff, Handoff::STATE_CONFIRMED, (string) $result['name']);
            }

            return new JSONResponse(['ok' => true] + $result, Http::STATUS_OK);
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

    /** The desktop asks for a handoff and shows its link as a QR code. */
    #[PublicPage]
    #[AnonRateLimit(limit: 30, period: 3600)]
    public function handoffCreate(): JSONResponse
    {
        return new JSONResponse(['ok' => true] + $this->handoff->create(), Http::STATUS_OK);
    }

    /** The desktop follows what the phone is doing. Read only, and the token is unguessable. */
    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 1200, period: 3600)]
    public function handoffStatus(string $token): JSONResponse
    {
        $state = $this->handoff->get($token);

        return new JSONResponse([
            'ok' => null !== $state,
            'state' => $state['state'] ?? 'expired',
            'name' => $state['name'] ?? '',
        ], Http::STATUS_OK);
    }

    /** @param array<string, mixed> $card */
    private static function ageFrom(array $card): ?int
    {
        if ('' !== (string) ($card['cnp'] ?? '') && ($card['cnpSure'] ?? false)) {
            return IdCardParser::ageFromCnp((string) $card['cnp']);
        }
        $birth = (string) ($card['birthDate'] ?? '');
        if ('' === $birth) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($birth))->diff(new \DateTimeImmutable('today'))->y;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function error(string $message): JSONResponse
    {
        return new JSONResponse(['ok' => false, 'message' => $message], Http::STATUS_OK);
    }
}
