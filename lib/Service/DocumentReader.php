<?php

declare(strict_types=1);

namespace OCA\IdRegister\Service;

/**
 * Picks the right reader for the picture: the driving licence when its title or its numbered
 * fields are on it, the identity card otherwise.
 */
final class DocumentReader
{
    public const TYPE_ID_CARD = 'id_card';
    public const TYPE_DRIVING_LICENCE = 'driving_licence';

    /**
     * @param list<string> $lines
     *
     * @return array{type:string, surname:string, givenNames:string, cnp:string, birthDate:string, confidence:float, cnpSure:bool, nameSure:bool, expiry?:string, expirySure?:bool}
     */
    public static function parse(array $lines): array
    {
        $card = IdCardParser::parse($lines);
        $cardResult = [
            'type' => self::TYPE_ID_CARD,
            'surname' => $card['surname'],
            'givenNames' => $card['givenNames'],
            'cnp' => $card['cnp'],
            'cnpSure' => $card['cnpSure'],
            'nameSure' => $card['surnameSure'] && $card['givenSure'],
            'birthDate' => '',
            'confidence' => $card['confidence'],
        ];
        if ($card['cnpSure']) {
            $birth = IdCardParser::birthDateFromCnp($card['cnp']);
            $cardResult['birthDate'] = null !== $birth ? $birth->format('Y-m-d') : '';
        }

        $licence = DrivingLicenceParser::parse($lines);
        $licenceResult = [
            'type' => self::TYPE_DRIVING_LICENCE,
            'surname' => $licence['surname'],
            'givenNames' => $licence['givenNames'],
            // field 4d of the licence is the personal number, with its check digit
            'cnp' => $licence['cnp'],
            'cnpSure' => $licence['cnpSure'],
            // a driving licence has no machine readable zone to check the name against
            'nameSure' => false,
            'expiry' => $licence['expiry'],
            'expirySure' => '' !== $licence['expiry'],
            'birthDate' => $licence['birthDate'],
            'confidence' => $licence['confidence'],
        ];

        // The printed title and the numbered fields tell a licence apart; the personal number
        // does not, since the licence carries one too (4d).
        if ($licence['looksLikeLicence']) {
            return $licenceResult;
        }

        return $cardResult;
    }
}
