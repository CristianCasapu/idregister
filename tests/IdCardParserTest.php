<?php

declare(strict_types=1);

require_once __DIR__.'/../lib/Service/IdCardParser.php';

use OCA\IdRegister\Service\IdCardParser as P;

$failed = 0;
$passed = 0;
function check(string $what, $actual, $expected): void
{
    global $failed, $passed;
    if ($actual === $expected) {
        ++$passed;

        return;
    }
    ++$failed;
    echo "FAIL {$what}\n  expected: ".var_export($expected, true)."\n  actual:   ".var_export($actual, true)."\n";
}

/** valid synthetic CNP: 12 digits + computed check digit */
function cnp(string $first12): string
{
    $sum = 0;
    for ($i = 0; $i < 12; ++$i) {
        $sum += (int) $first12[$i] * (int) P::CNP_WEIGHTS[$i];
    }
    $c = $sum % 11;

    return $first12.(10 === $c ? 1 : $c);
}

$valid = cnp('190032512345');
check('cnp valid', P::isValidCnp($valid), true);
check('cnp wrong check digit', P::isValidCnp(substr($valid, 0, 12).((int) $valid[12] + 1) % 10), false);
check('cnp too short', P::isValidCnp('123'), false);
check('cnp month 13', P::isValidCnp(cnp('191332512345')), false);
check('cnp sex 0', P::isValidCnp(cnp('090032512345')), false);

check('titleCase hyphen', P::titleCase('CRISTIAN-COSTINEL'), 'Cristian-Costinel');
check('titleCase two words', P::titleCase('ADINA GEORGIANA'), 'Adina Georgiana');
check('fixDigits', P::fixDigits('19OO32S1Z345'), '190032512345');

check('mrz names', P::namesFromMrz('IDROUPOPESCU<<ION<MARIN<<<<<<<'), ['POPESCU', 'ION MARIN']);
check('mrz names not an mrz line', P::namesFromMrz('Nume/Surname'), null);

// second MRZ line: 9 doc chars + check + ROU + birth(6) + check + sex + expiry(6) + check + optional(7) + check
$optional = substr($valid, 0, 1).substr($valid, 7, 6);
$mrz2 = 'RD1234567'.'8'.'ROU'.substr($valid, 1, 6).'1'.'M'.'3001015'.$optional.'4';
check('mrz cnp', P::cnpFromMrz($mrz2), $valid);

$card = [
    'ROMANIA', 'CARTE DE IDENTITATE / IDENTITY CARD',
    'Nume/Nom/Last name', 'POPESCU',
    'Prenume/Prenom/First name', 'ION-MARIN',
    'Cetatenie/Nationality', 'ROU',
    'CNP', $valid,
    'IDROUPOPESCU<<ION<MARIN<<<<<<<',
    $mrz2,
];
$r = P::parse($card);
check('parse surname', $r['surname'], 'Popescu');
check('parse given', $r['givenNames'], 'Ion-Marin');
check('parse cnp', $r['cnp'], $valid);
check('parse cnp sure', $r['cnpSure'], true);
check('parse surname sure (printed == mrz)', $r['surnameSure'], true);
check('parse confidence full', $r['confidence'], 1.0);

// only the MRZ is readable (worn card, glare on the printed side)
$r2 = P::parse(['IDROUIONESCU<<ANA<MARIA<<<<<<<']);
check('mrz only surname', $r2['surname'], 'Ionescu');
check('mrz only given', $r2['givenNames'], 'Ana Maria');
check('mrz only no cnp', $r2['cnpSure'], false);

// nothing usable
$r3 = P::parse(['blurry', '???']);
check('garbage surname', $r3['surname'], '');
check('garbage confidence', $r3['confidence'], 0.0);

echo $failed > 0 ? "\n{$failed} failed, {$passed} passed\n" : "all {$passed} assertions passed\n";
exit($failed > 0 ? 1 : 0);
