<?php

declare(strict_types=1);

namespace OCA\IdRegister\Service;

/**
 * Reads name, given names and CNP out of the text an OCR engine returned for a Romanian
 * identity card (both the 2021+ electronic card and the older one with an MRZ).
 *
 * Port of IdCardParser.kt from the NecMat app (github.com/CristianCasapu/necmat): the MRZ is
 * the most reliable source for the letters, the printed CNP (validated with its check digit)
 * is the anchor, and typical OCR confusions between letters and digits are corrected.
 *
 * Pure logic, no Nextcloud dependency, so it can be unit tested.
 */
final class IdCardParser
{
    public const CNP_WEIGHTS = '279146358279';

    private const LABEL_WORDS = [
        'nume', 'surname', 'nom', 'last name', 'prenume', 'prenom', 'given', 'first name',
        'cnp', 'pin', 'sex', 'cetatenie', 'nationality', 'nationalite',
        'data nasterii', 'date of birth', 'loc nastere', 'lieu de naissance', 'place of birth',
        'domiciliu', 'adresse', 'address', 'emis', 'delivree', 'issued', 'valabilitate',
        'validite', 'validity', 'nr. document', 'document no', 'semnatura', 'signature',
        'carte de identitate', 'identity card', "carte d'identite", 'romania', 'roumanie',
        'seria', 'data expirarii', 'date of expiry',
    ];

    /**
     * @param list<string> $rawLines lines of text as returned by the OCR engine
     *
     * @return array{surname:string, givenNames:string, cnp:string, surnameSure:bool, givenSure:bool, cnpSure:bool, confidence:float}
     */
    public static function parse(array $rawLines): array
    {
        $lines = array_values(array_filter(array_map('trim', $rawLines), static fn ($l) => '' !== $l));
        if (0 === \count($lines)) {
            return self::result('', '', '', false, false, false);
        }

        // ---- MRZ ----
        $mrzSurname = null;
        $mrzGiven = null;
        $mrzCnp = null;
        foreach ($lines as $line) {
            if (null === $mrzSurname && null !== ($names = self::namesFromMrz($line))) {
                [$mrzSurname, $mrzGiven] = $names;
            }
            if (null === $mrzCnp) {
                $mrzCnp = self::cnpFromMrz($line);
            }
        }

        // ---- printed CNP (preferably the one next to the "CNP" label) ----
        $printedCnp = null;
        $cnpLabelIdx = self::indexOfFirst($lines, static fn ($l) => str_contains(self::norm($l), 'cnp') || str_contains(self::norm($l), 'pin'));
        if ($cnpLabelIdx >= 0) {
            for ($j = $cnpLabelIdx, $max = min($cnpLabelIdx + 2, \count($lines) - 1); $j <= $max; ++$j) {
                $printedCnp = self::cnpCandidates($lines[$j])[0] ?? $printedCnp;
                if (null !== $printedCnp) {
                    break;
                }
            }
        }
        if (null === $printedCnp) {
            foreach ($lines as $line) {
                if (self::isMrzLine($line)) {
                    continue;
                }
                $printedCnp = self::cnpCandidates($line)[0] ?? null;
                if (null !== $printedCnp) {
                    break;
                }
            }
        }
        $cnp = $printedCnp ?? $mrzCnp ?? '';

        // ---- printed name / given names ----
        $surnameIdx = self::indexOfFirst($lines, static fn ($l) => self::isSurnameLabel($l));
        $givenIdx = self::indexOfFirst($lines, static fn ($l) => self::isGivenLabel($l));
        $printedSurname = $surnameIdx >= 0 ? self::valueAfter($lines, $surnameIdx) : null;
        $printedGiven = $givenIdx >= 0 ? self::valueAfter($lines, $givenIdx) : null;

        // The machine readable zone is printed in OCR-B, where I/T, O/0 and S/5 are easy to mix up.
        // When the same name is also printed elsewhere on the card and differs by a single letter,
        // the printed spelling wins: it is the one a human would read.
        $mrzSurname = self::correctAgainstText($mrzSurname, $lines);
        $mrzGiven = self::correctAgainstText($mrzGiven, $lines);

        [$surname, $surnameSure] = self::pick($printedSurname, $mrzSurname);
        [$given, $givenSure] = self::pick($printedGiven, $mrzGiven);

        return self::result(
            self::titleCase($surname),
            self::titleCase($given),
            $cnp,
            $surnameSure && '' !== $surname,
            $givenSure && '' !== $given,
            '' !== $cnp && self::isValidCnp($cnp),
        );
    }

    /**
     * Birth date encoded in a Romanian personal number, or null when it cannot be read.
     * The first digit says both the sex and the century: 1/2 → 1900s, 3/4 → 1800s,
     * 5/6 → 2000s, 7/8/9 → residents and foreigners, dated like 1/2.
     */
    public static function birthDateFromCnp(string $cnp): ?\DateTimeImmutable
    {
        if (!self::isValidCnp($cnp)) {
            return null;
        }
        $century = match ($cnp[0]) {
            '1', '2', '7', '8', '9' => 1900,
            '3', '4' => 1800,
            '5', '6' => 2000,
            default => null,
        };
        if (null === $century) {
            return null;
        }
        $year = $century + (int) substr($cnp, 1, 2);
        $month = (int) substr($cnp, 3, 2);
        $day = (int) substr($cnp, 5, 2);
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return (new \DateTimeImmutable())->setDate($year, $month, $day)->setTime(0, 0);
    }

    /** Age in whole years today, or null when the personal number cannot be read. */
    public static function ageFromCnp(string $cnp, ?\DateTimeImmutable $today = null): ?int
    {
        $birth = self::birthDateFromCnp($cnp);
        if (null === $birth) {
            return null;
        }

        return $birth->diff($today ?? new \DateTimeImmutable('today'))->y;
    }

    /** Romanian personal number: 13 digits, plausible date and a valid check digit. */
    public static function isValidCnp(string $cnp): bool
    {
        $s = trim($cnp);
        if (13 !== \strlen($s) || 1 !== preg_match('/^\d{13}$/', $s)) {
            return false;
        }
        if ('0' === $s[0]) {
            return false;
        }
        $month = (int) substr($s, 3, 2);
        $day = (int) substr($s, 5, 2);
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 12; ++$i) {
            $sum += (int) $s[$i] * (int) self::CNP_WEIGHTS[$i];
        }
        $control = $sum % 11;
        if (10 === $control) {
            $control = 1;
        }

        return $control === (int) $s[12];
    }

    /** "CRISTIAN-COSTINEL" → "Cristian-Costinel" */
    public static function titleCase(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name)) ?: [];
        $out = [];
        foreach ($words as $word) {
            if ('' === $word) {
                continue;
            }
            $parts = array_map(
                static fn (string $p): string => '' === $p ? $p : mb_strtoupper(mb_substr($p, 0, 1)).mb_strtolower(mb_substr($p, 1)),
                explode('-', $word),
            );
            $out[] = implode('-', $parts);
        }

        return implode(' ', $out);
    }

    /** (surname, given names) from the first MRZ line: IDROU + SURNAME<<GIVEN<NAMES */
    public static function namesFromMrz(string $line): ?array
    {
        $n = self::mrzNorm($line);
        if (1 !== preg_match('/^[I1]D[R][O0]U([A-Z<]+)$/', $n, $m)) {
            return null;
        }
        $parts = explode('<<', $m[1]);
        $surname = trim(str_replace('<', ' ', $parts[0]));
        if ('' === $surname) {
            return null;
        }
        $given = trim(str_replace('<', ' ', $parts[1] ?? ''));

        return [$surname, preg_replace('/\s+/', ' ', $given) ?? $given];
    }

    /** CNP rebuilt from the second MRZ line: sex digit + birth date + optional field. */
    public static function cnpFromMrz(string $line): ?string
    {
        $n = self::mrzNorm($line);
        $pattern = '/([A-Z0-9<]{9})([0-9OISB])ROU([0-9OISB]{6})([0-9OISB])([MF<])([0-9OISB]{6})([0-9OISB])([0-9OISB<]{7})([0-9OISB])?$/';
        if (1 !== preg_match($pattern, $n, $m)) {
            return null;
        }
        $dob = self::fixDigits($m[3]);
        $opt = str_replace('<', '', self::fixDigits($m[8]));
        if (\strlen($opt) < 7) {
            return null;
        }
        $cnp = substr($opt, 0, 1).$dob.substr($opt, 1, 6);

        return self::isValidCnp($cnp) ? $cnp : null;
    }

    /** Typical OCR letter→digit confusions, applied to numeric sequences only. */
    public static function fixDigits(string $s): string
    {
        return strtr($s, [
            'O' => '0', 'o' => '0', 'Q' => '0', 'D' => '0',
            'I' => '1', 'l' => '1', '|' => '1', 'i' => '1', '!' => '1',
            'Z' => '2', 'z' => '2', 'S' => '5', 's' => '5', 'B' => '8', 'G' => '6',
        ]);
    }

    /** Typical OCR digit→letter confusions, applied to names. */
    private static function fixLetters(string $s): string
    {
        return strtr($s, ['0' => 'O', '1' => 'I', '5' => 'S', '8' => 'B', '2' => 'Z', '6' => 'G']);
    }

    /**
     * @return array{surname:string, givenNames:string, cnp:string, surnameSure:bool, givenSure:bool, cnpSure:bool, confidence:float}
     */
    private static function result(string $surname, string $given, string $cnp, bool $surnameSure, bool $givenSure, bool $cnpSure): array
    {
        // how much of the card we are sure about: names carry the registration, the CNP confirms the read
        $confidence = 0.0;
        $confidence += '' !== $surname ? ($surnameSure ? 0.35 : 0.2) : 0.0;
        $confidence += '' !== $given ? ($givenSure ? 0.35 : 0.2) : 0.0;
        $confidence += $cnpSure ? 0.3 : 0.0;

        return [
            'surname' => $surname,
            'givenNames' => $given,
            'cnp' => $cnp,
            'surnameSure' => $surnameSure,
            'givenSure' => $givenSure,
            'cnpSure' => $cnpSure,
            'confidence' => round(min(1.0, $confidence), 2),
        ];
    }

    /**
     * Fix a name read from the machine readable zone against the same name printed on the card.
     *
     * @param list<string> $lines
     */
    private static function correctAgainstText(?string $name, array $lines): ?string
    {
        if (null === $name || '' === trim($name)) {
            return $name;
        }
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $fixed = [];
        foreach ($parts as $part) {
            $fixed[] = self::correctWord($part, $lines);
        }

        return implode(' ', $fixed);
    }

    /** @param list<string> $lines */
    private static function correctWord(string $word, array $lines): string
    {
        if (mb_strlen($word) < 4) {
            return $word;
        }
        foreach ($lines as $line) {
            if (self::isMrzLine($line)) {
                continue;
            }
            foreach (preg_split('/[^\p{L}\-]+/u', $line) ?: [] as $token) {
                $token = trim($token, '-');
                if (mb_strlen($token) !== mb_strlen($word)) {
                    continue;
                }
                if (1 === self::differences(mb_strtoupper($token), mb_strtoupper($word))) {
                    return mb_strtoupper($token);
                }
            }
        }

        return $word;
    }

    /** How many characters differ, stopping at 2 (that is all the caller needs). */
    private static function differences(string $a, string $b): int
    {
        $count = 0;
        $length = mb_strlen($a);
        for ($i = 0; $i < $length; ++$i) {
            if (mb_substr($a, $i, 1) !== mb_substr($b, $i, 1)) {
                ++$count;
                if ($count > 1) {
                    return 2;
                }
            }
        }

        return $count;
    }

    /** @return array{0:string,1:bool} value and whether it is confirmed by two sources */
    private static function pick(?string $printed, ?string $mrz): array
    {
        $p = (null !== $printed && '' !== trim($printed)) ? trim($printed) : null;
        $m = (null !== $mrz && '' !== trim($mrz)) ? trim($mrz) : null;
        if (null !== $p && null !== $m) {
            // the printed value keeps the hyphens, the MRZ is safer on the letters
            return self::lettersOnly($p) === self::lettersOnly($m) ? [$p, true] : [$m, false];
        }
        if (null !== $p) {
            return [$p, false];
        }
        if (null !== $m) {
            return [$m, true];
        }

        return ['', false];
    }

    /** The value under a label: the first line that is not a label and looks like a name. */
    private static function valueAfter(array $lines, int $labelIdx): ?string
    {
        for ($j = $labelIdx + 1, $max = min($labelIdx + 2, \count($lines) - 1); $j <= $max; ++$j) {
            $cand = $lines[$j];
            if (self::isLabel($cand) || self::isMrzLine($cand)) {
                continue;
            }
            // at most 2 digits (a misread letter), otherwise it is a date or a number
            if (preg_match_all('/\d/', $cand) > 2) {
                continue;
            }
            $cleaned = self::cleanName($cand);
            if (preg_match_all('/\p{L}/u', $cleaned) >= 2) {
                return $cleaned;
            }
        }

        return null;
    }

    private static function cleanName(string $raw): string
    {
        $s = self::fixLetters($raw);
        $s = preg_replace("/[^\p{L}\-' ]/u", ' ', $s) ?? $s;
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);

        // Single letters next to a name are leftovers from the label or from a stamp
        // ("d „> IONESCU" → "IONESCU"); no Romanian given name is one letter long.
        $words = array_values(array_filter(
            explode(' ', $s),
            static fn (string $word): bool => mb_strlen(trim($word, "-'")) > 1,
        ));

        return implode(' ', $words);
    }

    /** @return list<string> */
    private static function cnpCandidates(string $line): array
    {
        $out = [];
        $compact = str_replace(' ', '', $line);
        if (preg_match_all('/[0-9OoQDIl|i!ZzSsBG]{13,}/', $compact, $matches)) {
            foreach ($matches[0] as $raw) {
                if (preg_match_all('/\d/', $raw) < 5) {
                    continue;
                }
                $fixed = self::fixDigits($raw);
                for ($i = 0, $max = \strlen($fixed) - 13; $i <= $max; ++$i) {
                    $candidate = substr($fixed, $i, 13);
                    if (self::isValidCnp($candidate)) {
                        $out[] = $candidate;
                    }
                }
            }
        }

        return $out;
    }

    private static function mrzNorm(string $line): string
    {
        return str_replace([' ', '«', '‹', '(', '['], ['', '<', '<', '<', '<'], mb_strtoupper($line));
    }

    private static function isMrzLine(string $line): bool
    {
        $n = self::mrzNorm($line);

        return \strlen($n) >= 20 && (
            str_contains($n, '<<')
            || 1 === preg_match('/^[I1]D[R][O0]U/', $n)
            || 1 === preg_match('/ROU[0-9OISB]{7}[MF<][0-9OISB]{7}/', $n)
        );
    }

    private static function isLabel(string $line): bool
    {
        $n = self::norm($line);
        foreach (self::LABEL_WORDS as $word) {
            if (str_contains($n, $word)) {
                return true;
            }
        }

        return false;
    }

    private static function isSurnameLabel(string $line): bool
    {
        $n = self::norm($line);
        $surname = str_contains($n, 'nume') || str_contains($n, 'surname') || str_contains($n, 'nom') || str_contains($n, 'last name');
        $given = str_contains($n, 'prenume') || str_contains($n, 'prenom') || str_contains($n, 'given') || str_contains($n, 'first');

        return $surname && !$given;
    }

    private static function isGivenLabel(string $line): bool
    {
        $n = self::norm($line);

        return str_contains($n, 'prenume') || str_contains($n, 'prenom') || str_contains($n, 'given') || str_contains($n, 'first name');
    }

    /** lowercase, without diacritics, single spaces */
    private static function norm(string $s): string
    {
        $s = self::stripDiacritics($s);

        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($s)) ?? $s);
    }

    private static function lettersOnly(string $s): string
    {
        return preg_replace('/[^a-z]/', '', self::norm($s)) ?? '';
    }

    public static function stripDiacritics(string $s): string
    {
        $map = [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
            'Ă' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ș' => 'S', 'Ş' => 'S', 'Ț' => 'T', 'Ţ' => 'T',
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'í' => 'i',
            'ó' => 'o', 'ö' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c',
        ];
        $s = strtr($s, $map);
        if (class_exists(\Transliterator::class)) {
            $tr = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
            if (null !== $tr) {
                $s = $tr->transliterate($s) ?: $s;
            }
        }

        return $s;
    }

    /** @param callable(string):bool $predicate */
    private static function indexOfFirst(array $lines, callable $predicate): int
    {
        foreach ($lines as $i => $line) {
            if ($predicate($line)) {
                return $i;
            }
        }

        return -1;
    }
}
