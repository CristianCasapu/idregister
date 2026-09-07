<?php

declare(strict_types=1);

namespace OCA\IdRegister\Service;

/**
 * Reads a Romanian driving licence. It has no machine readable zone and no personal number:
 * the fields are numbered instead — 1 surname, 2 given names, 3 date and place of birth,
 * 4a issued, 4b valid until, 4c authority, 5 licence number.
 */
final class DrivingLicenceParser
{
    private const MARKERS = [
        'permis de conducere', 'driving licence', 'driving license', 'permis de conduire',
    ];

    /**
     * @param list<string> $rawLines
     *
     * @return array{surname:string, givenNames:string, birthDate:string, number:string, confidence:float, looksLikeLicence:bool}
     */
    public static function parse(array $rawLines): array
    {
        $lines = array_values(array_filter(array_map('trim', $rawLines), static fn ($l) => '' !== $l));
        $looks = self::looksLikeLicence($lines);

        $surname = self::field($lines, '1');
        $given = self::field($lines, '2');
        $third = self::field($lines, '3', true);
        $number = self::field($lines, '5', true);

        $birth = self::dateIn($third);
        // some layouts put the date of birth on its own line right after "3."
        if ('' === $birth) {
            foreach ($lines as $line) {
                if (1 === preg_match('/^\s*3[.\s]/', $line)) {
                    $birth = self::dateIn($line);
                    if ('' !== $birth) {
                        break;
                    }
                }
            }
        }

        $confidence = 0.0;
        if ($looks) {
            $confidence += 0.2;
        }
        $confidence += '' !== $surname ? 0.3 : 0.0;
        $confidence += '' !== $given ? 0.3 : 0.0;
        $confidence += '' !== $birth ? 0.2 : 0.0;

        return [
            'surname' => IdCardParser::titleCase(self::cleanName($surname)),
            'givenNames' => IdCardParser::titleCase(self::cleanName($given)),
            'birthDate' => $birth,
            'number' => preg_replace('/[^A-Za-z0-9]/', '', $number) ?? '',
            'confidence' => round(min(1.0, $confidence), 2),
            'looksLikeLicence' => $looks,
        ];
    }

    /** @param list<string> $lines */
    public static function looksLikeLicence(array $lines): bool
    {
        $text = mb_strtolower(IdCardParser::stripDiacritics(implode(' ', $lines)));
        foreach (self::MARKERS as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        // the numbered fields 4a and 4b are unique to the licence
        return 1 === preg_match('/\b4a\b/i', $text) && 1 === preg_match('/\b4b\b/i', $text);
    }

    /**
     * The value of a numbered field, either after the number on the same line or on the next one.
     *
     * @param list<string> $lines
     */
    private static function field(array $lines, string $number, bool $allowDigits = false): string
    {
        $pattern = '/^\s*'.preg_quote($number, '/').'\s*[.)\-:]?\s*(.*)$/u';
        foreach ($lines as $i => $line) {
            if (1 !== preg_match($pattern, $line, $m)) {
                continue;
            }
            $value = trim($m[1]);
            if ('' !== $value && (self::plausible($value, $allowDigits))) {
                return $value;
            }
            // value on the following line
            $next = trim($lines[$i + 1] ?? '');
            if ('' !== $next && !preg_match('/^\s*\d\s*[a-c]?\s*[.)\-:]/u', $next) && self::plausible($next, $allowDigits)) {
                return $next;
            }
        }

        return '';
    }

    private static function plausible(string $value, bool $allowDigits): bool
    {
        if ($allowDigits) {
            return mb_strlen($value) >= 2;
        }

        return preg_match_all('/\p{L}/u', $value) >= 2 && preg_match_all('/\d/', $value) <= 1;
    }

    /** dd.mm.yyyy / dd-mm-yyyy / dd mm yyyy → Y-m-d */
    private static function dateIn(string $text): string
    {
        if (1 !== preg_match('/\b(\d{1,2})[.\-\/ ](\d{1,2})[.\-\/ ](\d{4})\b/', $text, $m)) {
            return '';
        }
        [, $day, $month, $year] = $m;
        if (!checkdate((int) $month, (int) $day, (int) $year)) {
            return '';
        }

        return \sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day);
    }

    private static function cleanName(string $raw): string
    {
        $s = preg_replace("/[^\p{L}\-' ]/u", ' ', $raw) ?? $raw;

        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }
}
