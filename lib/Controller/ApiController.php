<?php

declare(strict_types=1);

namespace OCA\IdRegister\Controller;

use OCA\IdRegister\AppInfo\Application;
use OCA\IdRegister\Db\PendingRegistration;
use OCA\IdRegister\Service\Device;
use OCA\IdRegister\Service\Exception\AlreadyRegisteredException;
use OCA\IdRegister\Service\DocumentReader;
use OCA\IdRegister\Service\FaceMatch;
use OCA\IdRegister\Service\Liveness;
use OCA\IdRegister\Service\LiveScan;
use OCA\IdRegister\Service\Handoff;
use OCA\IdRegister\Service\IdCardParser;
use OCA\IdRegister\Service\Ocr;
use OCA\IdRegister\Service\Registration;
use OCA\IdRegister\Service\SelfieGuide;
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
        private LiveScan $liveScan,
        private ISecureRandom $random,
        private IL10N $l,
        private LoggerInterface $logger,
        private \OCP\IURLGenerator $urlGenerator,
        private SelfieGuide $selfieGuide,
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
        // a single picture: a copy or a screen is refused; anything else goes to an administrator
        $card['liveness'] = Liveness::single($card['live'] ?? null);
        unset($card['live']);
        if ((bool) $this->settings->get('requirePhysical') && Liveness::VERDICT_MONO === $card['liveness']) {
            return $this->error($this->l->t('This looks like a black-and-white copy. Please use the physical document.'));
        }
        if ((bool) $this->settings->get('requirePhysical') && Liveness::VERDICT_SCREEN === $card['liveness']) {
            return $this->error($this->l->t('This looks like a picture on a screen. Please use the physical document.'));
        }

        return new JSONResponse($this->acceptDocument($card, $data, $handoff), Http::STATUS_OK);
    }

    /**
     * Live scanning with the camera (see Service\LiveScan): one frame per call. The answer
     * carries the guidance for the phone; when the document is read (or the visitor asks to use
     * what was read so far, final=1) the same checks as for a picture apply and a scan id is given.
     */
    #[UseSession]
    #[PublicPage]
    #[AnonRateLimit(limit: 2000, period: 3600)]
    public function scanFrame(string $handoff = '', int $start = 0, int $final = 0): JSONResponse
    {
        if (!$this->settings->get('registrationOpen')) {
            return $this->error($this->l->t('Registration is currently closed.'), true);
        }
        if ($this->settings->get('mobileOnly') && !Device::isMobile((string) $this->request->getHeader('User-Agent'))) {
            return $this->error($this->l->t('Please continue on a phone or a tablet.'), true);
        }
        if (1 === $start) {
            $this->liveScan->reset();
        }
        $file = $this->request->getUploadedFile('frame');
        if (null === $file || !isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return $this->error($this->l->t('No picture was received.'));
        }
        if (($file['size'] ?? 0) > 4 * 1024 * 1024) {
            return $this->error($this->l->t('The picture is too large (maximum 12 MB).'));
        }
        $data = file_get_contents($file['tmp_name']);
        @unlink($file['tmp_name']);
        if (false === $data || '' === $data) {
            return $this->error($this->l->t('The picture could not be read.'));
        }

        $acceptIdCard = (bool) $this->settings->get('acceptIdCard');
        $acceptLicence = (bool) $this->settings->get('acceptDrivingLicence');
        try {
            $frame = $this->liveScan->frame($data, $acceptIdCard, $acceptLicence, (bool) $this->settings->get('requireConfirmedName'), (bool) $this->settings->get('requirePhysical'), (bool) $this->settings->get('requireValidDocument'));
        } catch (\Throwable $e) {
            unset($data);
            $this->logger->error('idregister: a live frame could not be read', ['exception' => $e]);

            return $this->error($this->l->t('The card reader is not available on this server. Please tell the administrator.'), true);
        }

        if (!$frame['done'] && 1 !== $final) {
            unset($data);

            return new JSONResponse(['ok' => true, 'done' => false] + $frame, Http::STATUS_OK);
        }
        if (1 === $final && !$this->liveScan->hasSomething()) {
            unset($data);

            return new JSONResponse(['ok' => false, 'done' => false, 'message' => $this->l->t('Nothing has been read yet. Hold the document inside the frame.')] + $frame, Http::STATUS_OK);
        }
        if ('' !== $frame['blocked']) {
            unset($data);

            return new JSONResponse(['ok' => false, 'done' => false, 'message' => $frame['status']] + $frame, Http::STATUS_OK);
        }

        // the document is read: the last frame is the picture the rest of the checks work on
        $card = $this->liveScan->result($acceptIdCard, $acceptLicence);
        $card['liveness'] = $this->liveScan->liveness();
        $this->liveScan->reset();
        $answer = $this->acceptDocument($card, $data, $handoff);
        unset($data);

        return new JSONResponse(['done' => true] + $answer + ['status' => $frame['status'], 'level' => $frame['level'], 'boxes' => $frame['boxes']], Http::STATUS_OK);
    }

    /**
     * The checks every document goes through, whichever way it was read, and the scan id the
     * browser gets in return. What was read stays on the server.
     *
     * @param array{type:string, surname:string, givenNames:string, cnp:string, cnpSure:bool, nameSure:bool, birthDate:string, confidence:float} $card
     * @param string $data the picture of the document (only used for the face on it, then dropped)
     */
    private function acceptDocument(array $card, string $data, string $handoff): array
    {
        // which documents does the administrator take?
        $accepted = DocumentReader::TYPE_DRIVING_LICENCE === $card['type']
            ? (bool) $this->settings->get('acceptDrivingLicence')
            : (bool) $this->settings->get('acceptIdCard');
        if (!$accepted) {
            unset($data);

            return ['ok' => false, 'message' => DocumentReader::TYPE_DRIVING_LICENCE === $card['type']
                ? $this->l->t('A driving licence is not accepted here. Please use your identity card.')
                : $this->l->t('An identity card is not accepted here. Please use your driving licence.')];
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

        // the card has to be valid: an expired one is refused; one whose expiry could not be read
        // goes through, but an administrator looks at it
        $expiryUnknown = false;
        if ($enough && DocumentReader::TYPE_ID_CARD === $card['type'] && (bool) $this->settings->get('requireValidDocument')) {
            $expiry = (string) ($card['expiry'] ?? '');
            $sure = (bool) ($card['expirySure'] ?? false);
            if ('' !== $expiry && $expiry < (new \DateTimeImmutable('today'))->format('Y-m-d')) {
                if ($sure) {
                    return ['ok' => false, 'message' => $this->l->t('This identity card has expired (%s). Registration needs a valid one.', [substr($expiry, 8, 2).'.'.substr($expiry, 5, 2).'.'.substr($expiry, 0, 4)])];
                }
                // a date in the past that is not clearly the expiry (the birth date, say): as good as unread
                $expiry = '';
            }
            $expiryUnknown = '' === $expiry;
        }

        $age = self::ageFrom($card);
        $minAge = (int) $this->settings->get('minAge');
        if ($enough && $minAge > 0 && null !== $age && $age < $minAge) {
            return ['ok' => false, 'message' => $this->l->t('You have to be at least %d years old to register here.', [$minAge])];
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

                    return ['ok' => false, 'message' => $this->l->t('The photo on the document could not be found. Take the picture again, with the whole document in the frame.')];
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
                'liveness' => (string) ($card['liveness'] ?? Liveness::VERDICT_UNSURE),
                'expiry' => (string) ($card['expiry'] ?? ''),
                'expiryUnknown' => $expiryUnknown,
                'time' => time(),
            ]);
            if ('' !== $handoff) {
                $this->handoff->advance($handoff, Handoff::STATE_DOCUMENT, trim($card['givenNames'].' '.$card['surname']));
            }
        }
        unset($data);

        return [
            'ok' => $enough,
            'scanId' => $enough ? $scanId : '',
            'type' => $card['type'],
            'surname' => $card['surname'],
            'givenNames' => $card['givenNames'],
            'confidence' => $card['confidence'],
            'needsSelfie' => $needsSelfie,
            'can' => (string) ($card['can'] ?? ''),
            'message' => $enough ? '' : ($message ?? $this->l->t('The identity card could not be read. Try again with more light and the whole card in the frame.')),
        ];
    }

    /**
     * What the chip of the electronic identity card said, read by the phone app through NFC
     * (PACE with the printed access number): the machine readable data and the photo. A chip
     * that answered is proof of a physical card; its data replaces what the camera read, and its
     * photo becomes the reference for the selfie. Nothing of it is kept beyond the session.
     */
    #[UseSession]
    #[PublicPage]
    #[AnonRateLimit(limit: 30, period: 3600)]
    public function chip(
        string $scanId = '',
        string $surname = '',
        string $givenNames = '',
        string $documentNumber = '',
        string $personalNumber = '',
        string $dateOfBirth = '',
        string $dateOfExpiry = '',
        string $nationality = '',
    ): JSONResponse {
        // the chip's data comes from the phone unsigned for now: only when the administrator switched it on
        if (!(bool) $this->settings->get('chipEnabled')) {
            return $this->error($this->l->t('The chip reading is not enabled on this server.'), true);
        }
        $card = $this->session->get(self::SESSION_PREFIX.$scanId);
        if (!\is_array($card) || (time() - (int) ($card['time'] ?? 0)) > self::SCAN_TTL) {
            return $this->error($this->l->t('Please read your identity card again.'));
        }
        $surname = IdCardParser::titleCase(trim($surname));
        $givenNames = IdCardParser::titleCase(trim($givenNames));
        if ('' === $surname || '' === $givenNames) {
            return $this->error($this->l->t('The chip gave no name.'));
        }
        $cnp = preg_replace('/\D/', '', $personalNumber) ?? '';
        if ('' !== $cnp && !IdCardParser::isValidCnp($cnp)) {
            $cnp = '';
        }
        $expiry = null;
        if (preg_match('/^\d{6}$/', $dateOfExpiry)) {
            $expiry = \DateTimeImmutable::createFromFormat('ymd', $dateOfExpiry);
            if ($expiry && $expiry < new \DateTimeImmutable('today')) {
                return $this->error($this->l->t('This identity card has expired.'));
            }
        }
        // the chip is authoritative: the name from it replaces what the camera read
        $changed = IdCardParser::stripDiacritics(mb_strtolower($surname.'|'.$givenNames))
            !== IdCardParser::stripDiacritics(mb_strtolower((string) $card['surname'].'|'.(string) $card['givenNames']));
        if ($changed) {
            $this->logger->info('idregister: the chip corrected the name read by the camera');
        }
        $card['surname'] = $surname;
        $card['givenNames'] = $givenNames;
        if ('' !== $cnp) {
            $card['cnp'] = $cnp;
            $birth = IdCardParser::birthDateFromCnp($cnp);
            $card['birthDate'] = null !== $birth ? $birth->format('Y-m-d') : (string) ($card['birthDate'] ?? '');
            $card['age'] = IdCardParser::ageFromCnp($cnp);
        }
        $card['liveness'] = 'chip';
        if (preg_match('/^\d{6}$/', $dateOfExpiry) && $expiry) {
            $card['expiry'] = $expiry->format('Y-m-d');
            $card['expiryUnknown'] = false;
        }
        $card['chipDocument'] = mb_substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($documentNumber)) ?? '', 0, 12);

        // the photo on the chip: the face the selfie is compared with (better than the printed one)
        $needsSelfie = (bool) $this->settings->get('requireSelfie') && $this->faceMatch->available();
        $file = $this->request->getUploadedFile('face');
        if ($needsSelfie && null !== $file && isset($file['tmp_name']) && is_uploaded_file($file['tmp_name']) && ($file['size'] ?? 0) <= 2 * 1024 * 1024) {
            $data = (string) file_get_contents($file['tmp_name']);
            @unlink($file['tmp_name']);
            $face = $this->faceMatch->describe($data, 0.05);
            unset($data);
            if (\count($face['vector']) > 0) {
                $card['faceVector'] = $face['vector'];
                $card['selfie'] = '';
            }
        }
        $this->session->set(self::SESSION_PREFIX.$scanId, $card);

        return new JSONResponse([
            'ok' => true,
            'surname' => $surname,
            'givenNames' => $givenNames,
            'needsSelfie' => $needsSelfie && '' === (string) ($card['selfie'] ?? ''),
            'corrected' => $changed,
        ], Http::STATUS_OK);
    }

    /**
     * The automatic selfie: where the face is in a small frame, and what to do (see SelfieGuide).
     */
    #[UseSession]
    #[PublicPage]
    #[AnonRateLimit(limit: 2000, period: 3600)]
    public function selfieGuide(string $scanId = '', string $phase = 'front'): JSONResponse
    {
        $card = $this->session->get(self::SESSION_PREFIX.$scanId);
        if (!\is_array($card)) {
            return $this->error($this->l->t('Please read your identity card again.'), true);
        }
        $phase = 'turn' === $phase ? 'turn' : 'front';
        $file = $this->request->getUploadedFile('frame');
        if (null === $file || !isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name']) || ($file['size'] ?? 0) > 512 * 1024) {
            return $this->error($this->l->t('No picture was received.'));
        }
        $data = (string) file_get_contents($file['tmp_name']);
        @unlink($file['tmp_name']);
        $guide = $this->selfieGuide->guide($data, $phase);
        unset($data);
        if ('turn' === $phase && ($guide['turned'] ?? false)) {
            // the head turned in front of the camera: remembered on the server, the page cannot claim it
            $card['selfieTurned'] = true;
            $this->session->set(self::SESSION_PREFIX.$scanId, $card);
        }

        return new JSONResponse(['ok' => true] + $guide, Http::STATUS_OK);
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
        if (FaceMatch::VERDICT_COPY === $result['verdict']) {
            return $this->error($this->l->t('That is the photo on the document, not a selfie. Please take a selfie with the camera.'));
        }
        if (FaceMatch::VERDICT_DIFFERENT === $result['verdict']) {
            $this->logger->info('idregister: the selfie does not match the document (distance '.$result['distance'].')');

            return $this->error($this->l->t('The selfie does not match the photo on the document.'));
        }

        $card['selfie'] = $result['verdict'];
        // no head turn seen during this selfie: a real match still, but an administrator has a look
        $card['selfieLive'] = !(bool) $this->settings->get('requireSelfieLiveness') || (bool) ($card['selfieTurned'] ?? false);
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
                    'needsReview' => FaceMatch::VERDICT_REVIEW === ($card['selfie'] ?? '')
                    || ((bool) $this->settings->get('requirePhysical') && !\in_array((string) ($card['liveness'] ?? Liveness::VERDICT_UNSURE), [Liveness::VERDICT_OK, 'chip'], true))
                    || (bool) ($card['expiryUnknown'] ?? false)
                    || !(bool) ($card['selfieLive'] ?? true),
                    'selfieDistance' => (float) ($card['selfieDistance'] ?? 0),
                ],
                $email,
                $phone,
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

    /**
     * Express: the account is created now, from the document (and the selfie) alone.
     */
    #[UseSession]
    #[PublicPage]
    #[AnonRateLimit(limit: 10, period: 3600)]
    public function express(string $scanId = '', string $handoff = '', bool $terms = false): JSONResponse
    {
        if (!$this->settings->get('expressMode')) {
            return $this->error($this->l->t('Registration is currently closed.'));
        }
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
            $result = $this->registration->express([
                'surname' => (string) $card['surname'],
                'givenNames' => (string) $card['givenNames'],
                'cnp' => (string) $card['cnp'],
                'type' => (string) ($card['type'] ?? DocumentReader::TYPE_ID_CARD),
                'birthDate' => (string) ($card['birthDate'] ?? ''),
                'needsReview' => FaceMatch::VERDICT_REVIEW === ($card['selfie'] ?? '')
                    || ((bool) $this->settings->get('requirePhysical') && !\in_array((string) ($card['liveness'] ?? Liveness::VERDICT_UNSURE), [Liveness::VERDICT_OK, 'chip'], true))
                    || (bool) ($card['expiryUnknown'] ?? false)
                    || !(bool) ($card['selfieLive'] ?? true),
                'selfieDistance' => (float) ($card['selfieDistance'] ?? 0),
            ]);
            $this->session->remove(self::SESSION_PREFIX.$scanId);
            if ('' !== $handoff) {
                if (PendingRegistration::STATUS_ACTIVE === (string) ($result['status'] ?? '')) {
                    // the desktop that showed the QR code signs in too
                    $this->handoff->finish($handoff, (string) $result['name'], (string) $result['uid'], (string) $result['password']);
                } else {
                    $this->handoff->advance($handoff, Handoff::STATE_CONFIRMED, (string) $result['name']);
                }
            }

            return new JSONResponse(['ok' => true] + $result, Http::STATUS_OK);
        } catch (AlreadyRegisteredException $e) {
            // they have an account: the page sends them to sign in instead of keeping them here
            return new JSONResponse(['ok' => false, 'existing' => true, 'message' => $e->getMessage(), 'loginUrl' => $this->urlGenerator->linkToRouteAbsolute('core.login.showLoginForm')], Http::STATUS_OK);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['ok' => false, 'closed' => true, 'message' => $e->getMessage(), 'loginUrl' => $this->urlGenerator->linkToRouteAbsolute('core.login.showLoginForm')], Http::STATUS_OK);
        } catch (\Throwable $e) {
            $this->logger->error('idregister: express registration failed', ['exception' => $e]);

            return $this->error($this->l->t('Something went wrong. Please try again.'));
        }
    }

    #[PublicPage]
    #[AnonRateLimit(limit: 20, period: 3600)]
    public function verifyCode(string $token = '', string $code = '', string $handoff = ''): JSONResponse
    {
        try {
            $result = $this->registration->confirmWithCode($token, $code);

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

    /**
     * The very last step: the visitor picks a password and the account is created.
     */
    #[PublicPage]
    #[AnonRateLimit(limit: 20, period: 3600)]
    public function finish(string $token = '', string $password = '', string $handoff = ''): JSONResponse
    {
        try {
            $result = $this->registration->finish($token, $password);
            if ('' !== $handoff) {
                $this->handoff->advance($handoff, Handoff::STATE_CONFIRMED, (string) $result['name']);
            }

            return new JSONResponse(['ok' => true] + $result, Http::STATUS_OK);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('idregister: the account could not be created', ['exception' => $e]);

            return $this->error($this->l->t('Something went wrong. Please try again.'));
        }
    }

    /** Is the server reachable? (the corner indicator of the page and of the app) */
    #[PublicPage]
    #[NoCSRFRequired]
    public function ping(): JSONResponse
    {
        return new JSONResponse(['ok' => true, 'time' => time()], Http::STATUS_OK);
    }

    /** The desktop asks for a handoff and shows its link as a QR code. */
    #[PublicPage]
    #[AnonRateLimit(limit: 30, period: 3600)]
    public function handoffCreate(): JSONResponse
    {
        // the answer carries the token (goes into the QR code) and a secret (stays in this page /
        // the app): only whoever holds both can pick up the sign-in at the end
        return new JSONResponse(['ok' => true] + $this->handoff->create(), Http::STATUS_OK);
    }

    /** The desktop follows what the phone is doing. Read only, and the token is unguessable. */
    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 1200, period: 3600)]
    public function handoffStatus(string $token, string $k = ''): JSONResponse
    {
        $state = $this->handoff->get($token);
        $answer = [
            'ok' => null !== $state,
            'state' => $state['state'] ?? 'expired',
            'name' => $state['name'] ?? '',
        ];
        if (null !== $state && Handoff::STATE_CONFIRMED === $state['state']) {
            $login = $this->handoff->takeLogin($token, $k);
            if (null !== $login) {
                $answer['login'] = $login;
            }
        }

        return new JSONResponse($answer, Http::STATUS_OK);
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

    private function error(string $message, bool $fatal = false): JSONResponse
    {
        if ($fatal) {
            return new JSONResponse(['ok' => false, 'fatal' => true, 'message' => $message], Http::STATUS_OK);
        }
        return new JSONResponse(['ok' => false, 'message' => $message], Http::STATUS_OK);
    }
}
