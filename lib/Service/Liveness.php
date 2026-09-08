<?php

declare(strict_types=1);

namespace OCA\IdRegister\Service;

/**
 * Is the document in front of the camera a physical one? The reading script measures every
 * frame (see src/ocr_frame.py: colour, a pixel grid in the spectrum, glare); this class turns
 * those numbers into a verdict over the last frames, so a single odd frame changes nothing:
 *
 *  - "mono":   no colour at all in several frames → a black-and-white copy;
 *  - "screen": sharp periodic peaks in the spectrum → a display photographed (moiré);
 *  - physical: the glare on the laminate moved between frames (the hand tilted the card),
 *              which neither a matte copy nor a screen does.
 *
 * Nothing here is bullet-proof against a determined forger; it stops the everyday cases
 * (a photo on a computer screen, a photocopy) without asking anything of an honest visitor.
 */
final class Liveness
{
    public const VERDICT_OK = 'ok';
    public const VERDICT_MONO = 'mono';
    public const VERDICT_SCREEN = 'screen';
    /** the passive checks found nothing wrong but nothing proved the card physical either */
    public const VERDICT_UNSURE = 'unsure';

    /** how many recent frames the verdict looks at */
    public const WINDOW = 6;
    /** frames of "tilt the card" before giving up and letting an administrator decide */
    public const TILT_FRAMES = 8;

    private const MONO_SAT = 0.06;
    private const MONO_COLOUR = 0.04;
    private const COLOUR_SAT = 0.09;
    private const COLOUR_FRAC = 0.08;
    private const SCREEN_MOIRE = 3.2;
    private const SCREEN_PEAKS = 30;
    private const GLARE_MOVE = 0.06;

    /**
     * Classify one frame's numbers.
     *
     * @param null|array<string, mixed> $live
     *
     * @return array{mono: bool, colour: bool, screen: bool, glare: ?array{0: float, 1: float}}
     */
    public static function frameFlags(?array $live): array
    {
        if (null === $live || isset($live['error'])) {
            return ['mono' => false, 'colour' => false, 'screen' => false, 'glare' => null];
        }
        $sat = (float) ($live['sat'] ?? 0);
        $colour = (float) ($live['colour'] ?? 0);
        $glare = null;
        if (\is_array($live['glareAt'] ?? null) && 2 === \count($live['glareAt'])) {
            $glare = [(float) $live['glareAt'][0], (float) $live['glareAt'][1]];
        }

        return [
            'mono' => $sat < self::MONO_SAT && $colour < self::MONO_COLOUR,
            'colour' => $sat > self::COLOUR_SAT || $colour > self::COLOUR_FRAC,
            'screen' => (float) ($live['moire'] ?? 0) >= self::SCREEN_MOIRE && (int) ($live['peaks'] ?? 0) >= self::SCREEN_PEAKS,
            'glare' => $glare,
        ];
    }

    /**
     * The verdict over the recent frames: mono / screen when the evidence repeats, ok when the
     * glare moved (physical), unsure otherwise.
     *
     * @param list<array{mono: bool, colour: bool, screen: bool}> $recent the last frames, oldest first
     * @param list<array{0: float, 1: float}>                     $glare  where the glare was, over the whole scan
     */
    public static function verdict(array $recent, array $glare): string
    {
        $recent = \array_slice($recent, -self::WINDOW);
        $mono = 0;
        $colour = 0;
        $screen = 0;
        foreach ($recent as $f) {
            $mono += $f['mono'] ? 1 : 0;
            $colour += $f['colour'] ? 1 : 0;
            $screen += $f['screen'] ? 1 : 0;
        }
        if ($mono >= 3 && 0 === $colour) {
            return self::VERDICT_MONO;
        }
        if ($screen >= 2) {
            return self::VERDICT_SCREEN;
        }

        return self::physical($glare) ? self::VERDICT_OK : self::VERDICT_UNSURE;
    }

    /**
     * A single picture (the photo fallback): only the clear rejections are possible.
     *
     * @param null|array<string, mixed> $live
     */
    public static function single(?array $live): string
    {
        $f = self::frameFlags($live);
        if ($f['mono']) {
            return self::VERDICT_MONO;
        }
        if ($f['screen']) {
            return self::VERDICT_SCREEN;
        }

        return self::VERDICT_UNSURE;
    }

    /** @param list<array{0: float, 1: float}> $glare */
    public static function physical(array $glare): bool
    {
        $n = \count($glare);
        for ($i = 0; $i < $n; ++$i) {
            for ($j = $i + 1; $j < $n; ++$j) {
                $dx = $glare[$i][0] - $glare[$j][0];
                $dy = $glare[$i][1] - $glare[$j][1];
                if (sqrt($dx * $dx + $dy * $dy) >= self::GLARE_MOVE) {
                    return true;
                }
            }
        }

        return false;
    }
}
