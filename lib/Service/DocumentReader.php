<?php

declare(strict_types=1);

namespace OCA\IdRegister\Service;

/**
 * Picks the right reader for the picture: identity card first (it carries a machine readable
 * zone and a personal number, so it is the more reliable of the two), driving licence otherwise.
 */
final class DocumentReader
{
    public const TYPE_ID_CARD = 'id_card';
    public const TYPE_DRIVING_LICENCE = 'driving_licence';

    /**
     * @param list<string> $lines
     *
     * @return array{type:string, surname:string, givenNames:string, cnp:string, birthDate:string, confidence:float, cnpSure:bool, nameSure:bool}
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
            'cnp' => '',
            'cnpSure' => false,
            // a driving licence has no machine readable zone to check the name against
            'nameSure' => false,
            'birthDate' => $licence['birthDate'],
            'confidence' => $licence['confidence'],
        ];

        // A licence is only chosen when the picture really looks like one: the identity card
        // reader also finds names on a licence, but without the personal number to back them up.
        if ($licence['looksLikeLicence'] && $licenceResult['confidence'] >= $cardResult['confidence'] && !$card['cnpSure']) {
            return $licenceResult;
        }

        return $cardResult;
    }
}
