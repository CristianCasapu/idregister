<?php

declare(strict_types=1);

namespace OCA\IdRegister\Service;

use OCP\IL10N;
use OCP\ISession;

/**
 * Live reading of the document with the phone's camera, the way NecMat does it: every frame is
 * read on its own, the fields found are added to what earlier frames gave (the personal number
 * from one frame, the name from another), the visitor is guided (closer, further, hold still)
 * and the scan finishes by itself once the personal number came out identical in two frames.
 *
 * Only the fields live in the session; no frame is ever kept.
 */
final class LiveScan
{
    private const KEY = 'idregister.live';
    /** the guide inside a frame: the phone crops the guide plus 8 % (width) / 15 % (height) around it */
    private const GUIDE_X = 0.08 / 1.16;
    private const GUIDE_Y = 0.15 / 1.30;

    public function __construct(
        private ISession $session,
        private Ocr $ocr,
        private IL10N $l,
    ) {}

    public function reset(): void
    {
        $this->session->remove(self::KEY);
    }

    /**
     * Read one frame and add it to the running result.
     *
     * @return array{status:string, level:int, boxes:list<array{x:float,y:float,w:float,h:float}>, done:bool, lines:int, frames:int}
     */
    public function frame(string $jpeg, bool $acceptIdCard, bool $acceptLicence, bool $requireConfirmedName): array
    {
        $state = $this->session->get(self::KEY);
        if (!\is_array($state)) {
            $state = ['card' => IdCardParser::empty(), 'licence' => null, 'stableCnp' => '', 'stableCount' => 0, 'stableName' => '', 'nameCount' => 0, 'frames' => 0, 'looksLikeLicence' => 0];
        }

        $read = $this->ocr->readFrame($jpeg);
        $lines = $read['lines'];
        ++$state['frames'];

        $frameCard = IdCardParser::parse($lines);
        $state['card'] = IdCardParser::merge($state['card'], $frameCard);
        if ($frameCard['cnpSure']) {
            if ($frameCard['cnp'] === $state['stableCnp']) {
                ++$state['stableCount'];
            } else {
                $state['stableCnp'] = $frameCard['cnp'];
                $state['stableCount'] = 1;
            }
        }

        if ($acceptLicence) {
            $licence = DrivingLicenceParser::parse($lines);
            if ($licence['looksLikeLicence']) {
                ++$state['looksLikeLicence'];
            }
            if ('' !== $licence['surname'] || '' !== $licence['givenNames']) {
                $previous = $state['licence'] ?? ['surname' => '', 'givenNames' => '', 'birthDate' => '', 'confidence' => 0.0];
                $state['licence'] = [
                    'surname' => '' !== $licence['surname'] ? $licence['surname'] : $previous['surname'],
                    'givenNames' => '' !== $licence['givenNames'] ? $licence['givenNames'] : $previous['givenNames'],
                    'birthDate' => '' !== $licence['birthDate'] ? $licence['birthDate'] : $previous['birthDate'],
                    'confidence' => max((float) $licence['confidence'], (float) $previous['confidence']),
                ];
                $name = $licence['surname'].'|'.$licence['givenNames'];
                if ('' !== $licence['surname'] && '' !== $licence['givenNames']) {
                    if ($name === $state['stableName']) {
                        ++$state['nameCount'];
                    } else {
                        $state['stableName'] = $name;
                        $state['nameCount'] = 1;
                    }
                }
            }
        }

        $card = $state['card'];
        $namesOk = '' !== $card['surname'] && '' !== $card['givenNames']
            && (!$requireConfirmedName || ($card['surnameSure'] && $card['givenSure']));
        $idDone = $acceptIdCard && $card['cnpSure'] && $namesOk && $state['stableCount'] >= 2;
        $licenceDone = $acceptLicence && !$card['cnpSure'] && $state['looksLikeLicence'] >= 2
            && null !== $state['licence'] && $state['nameCount'] >= 2;
        $done = $idDone || $licenceDone;

        [$status, $level] = $this->assess($lines, $read['boxes'], $card, $state, $acceptLicence, $done);

        $this->session->set(self::KEY, $state);

        return [
            'status' => $status,
            'level' => $level,
            'boxes' => $read['boxes'],
            'done' => $done,
            'lines' => \count($lines),
            'frames' => (int) $state['frames'],
        ];
    }

    /** Has anything been read so far (so "use what was read" makes sense)? */
    public function hasSomething(): bool
    {
        $state = $this->session->get(self::KEY);
        if (!\is_array($state)) {
            return false;
        }

        return '' !== $state['card']['surname'] || '' !== $state['card']['givenNames'] || '' !== $state['card']['cnp'] || null !== $state['licence'];
    }

    /**
     * The document as the rest of the registration expects it (same shape as Ocr::readDocument()).
     *
     * @return array{type:string, surname:string, givenNames:string, cnp:string, cnpSure:bool, nameSure:bool, birthDate:string, confidence:float, lines:int}
     */
    public function result(bool $acceptIdCard, bool $acceptLicence): array
    {
        $state = $this->session->get(self::KEY);
        $card = \is_array($state) ? $state['card'] : IdCardParser::empty();
        $licence = \is_array($state) ? $state['licence'] : null;
        $looksLikeLicence = \is_array($state) && $state['looksLikeLicence'] >= 1;

        $cardResult = [
            'type' => DocumentReader::TYPE_ID_CARD,
            'surname' => $card['surname'],
            'givenNames' => $card['givenNames'],
            'cnp' => $card['cnp'],
            'cnpSure' => $card['cnpSure'],
            'nameSure' => $card['surnameSure'] && $card['givenSure'],
            'birthDate' => '',
            'confidence' => $card['confidence'],
            'lines' => 0,
        ];
        if ($card['cnpSure']) {
            $birth = IdCardParser::birthDateFromCnp($card['cnp']);
            $cardResult['birthDate'] = null !== $birth ? $birth->format('Y-m-d') : '';
        }

        if ($acceptLicence && null !== $licence && $looksLikeLicence && !$card['cnpSure']
            && (!$acceptIdCard || $licence['confidence'] >= $card['confidence'])) {
            return [
                'type' => DocumentReader::TYPE_DRIVING_LICENCE,
                'surname' => $licence['surname'],
                'givenNames' => $licence['givenNames'],
                'cnp' => '',
                'cnpSure' => false,
                'nameSure' => false,
                'birthDate' => $licence['birthDate'],
                'confidence' => $licence['confidence'],
                'lines' => 0,
            ];
        }

        return $cardResult;
    }

    /**
     * Guidance for this frame: 0 = red (nothing useful), 1 = amber (adjust), 2 = green (read).
     *
     * @param list<string> $lines
     * @param list<array{x:float,y:float,w:float,h:float}> $boxes
     *
     * @return array{0:string, 1:int}
     */
    private function assess(array $lines, array $boxes, array $card, array $state, bool $acceptLicence, bool $done): array
    {
        if ($done) {
            return [$this->l->t('Read ✓ — hold still'), 2];
        }
        $text = IdCardParser::stripDiacritics(mb_strtolower(implode(' ', $lines)));
        $looksLikeId = false;
        foreach (['roman', 'identit', 'cnp', 'idrou', 'carte de', 'permis', 'conducere', 'driving', 'licen'] as $word) {
            if (str_contains($text, $word)) {
                $looksLikeId = true;
                break;
            }
        }
        if (\count($lines) < 3 || 0 === \count($boxes)) {
            return [$this->l->t('Point the camera at the document, inside the frame'), 0];
        }

        // the text's bounding box against the guide, both relative to the frame
        $l = 1.0;
        $t = 1.0;
        $r = 0.0;
        $b = 0.0;
        foreach ($boxes as $box) {
            $l = min($l, $box['x']);
            $t = min($t, $box['y']);
            $r = max($r, $box['x'] + $box['w']);
            $b = max($b, $box['y'] + $box['h']);
        }
        $guideL = self::GUIDE_X;
        $guideR = 1.0 - self::GUIDE_X;
        $guideT = self::GUIDE_Y;
        $guideB = 1.0 - self::GUIDE_Y;
        $guideW = $guideR - $guideL;
        $guideH = $guideB - $guideT;
        $widthRatio = ($r - $l) / $guideW;
        $outside = $l < $guideL - $guideW * 0.06 || $r > $guideR + $guideW * 0.06
            || $t < $guideT - $guideH * 0.1 || $b > $guideB + $guideH * 0.1;

        if (!$looksLikeId) {
            return [$this->l->t('This does not look like a Romanian identity document'), 0];
        }
        if ($widthRatio < 0.55) {
            return [$this->l->t('Move closer to the document'), 1];
        }
        if ($outside) {
            return [$this->l->t('Move back a little, the document leaves the frame'), 1];
        }
        $licenceMode = $acceptLicence && $state['looksLikeLicence'] >= 1 && !$card['cnpSure'];
        if ($licenceMode) {
            return [null !== $state['licence'] && '' !== $state['licence']['surname']
                ? $this->l->t('Name read ✓ — hold still')
                : $this->l->t('Hold still… reading the document'), 1];
        }
        if ($card['cnpSure'] && ('' === $card['surname'] || '' === $card['givenNames'])) {
            return [$this->l->t('Personal number read ✓ — looking for the name, hold the document straight'), 1];
        }
        if ($card['cnpSure']) {
            return [$this->l->t('Almost there — hold still'), 1];
        }
        if ('' !== $card['surname'] && '' === $card['cnp']) {
            return [$this->l->t('Name read ✓ — looking for the personal number, avoid reflections'), 1];
        }
        if ('' !== $card['cnp'] && !$card['cnpSure']) {
            return [$this->l->t('The personal number was misread — avoid reflections, more light'), 1];
        }

        return [$this->l->t('Hold still… reading the document'), 1];
    }
}
