<?php

declare(strict_types=1);

namespace OCA\IdRegister\Service;

use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/**
 * Reads the text of an identity card with Tesseract. The picture is only ever a temporary
 * file: it is normalised (EXIF rotation, downscale, grayscale, contrast), read, and deleted
 * immediately afterwards — nothing about the card is stored.
 */
final class Ocr
{
    public const MAX_PX = 2200;
    public const LANGS = 'ron+eng';
    /** the MRZ uses OCR-B and only these characters */
    public const MRZ_WHITELIST = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789<';
    public const TIMEOUT = 60;

    public function __construct(
        private ITempManager $tempManager,
        private LoggerInterface $logger,
    ) {}

    public static function binary(): string
    {
        foreach (['/usr/bin/tesseract', '/usr/local/bin/tesseract', '/bin/tesseract'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }
        $which = @shell_exec('command -v tesseract 2>/dev/null');

        return \is_string($which) ? trim($which) : '';
    }

    /** @return array{ok:bool, version:string, languages:list<string>, missing:list<string>} */
    public static function status(): array
    {
        $binary = self::binary();
        if ('' === $binary) {
            return ['ok' => false, 'version' => '', 'languages' => [], 'missing' => ['tesseract']];
        }
        $version = '';
        $out = @shell_exec(escapeshellarg($binary).' --version 2>&1');
        if (\is_string($out) && preg_match('/tesseract\s+([\d.]+)/i', $out, $m)) {
            $version = $m[1];
        }
        $langs = [];
        $list = @shell_exec(escapeshellarg($binary).' --list-langs 2>&1');
        if (\is_string($list)) {
            foreach (explode("\n", $list) as $line) {
                $line = trim($line);
                if ('' !== $line && !str_contains($line, ' ')) {
                    $langs[] = $line;
                }
            }
        }
        $missing = [];
        foreach (['ron', 'eng'] as $lang) {
            if (!\in_array($lang, $langs, true)) {
                $missing[] = 'tesseract-ocr-'.$lang;
            }
        }

        return ['ok' => 0 === \count($missing), 'version' => $version, 'languages' => $langs, 'missing' => $missing];
    }

    /**
     * Read an identity card.
     *
     * @param string $imageData raw bytes of the uploaded picture
     *
     * @return array{surname:string, givenNames:string, cnp:string, surnameSure:bool, givenSure:bool, cnpSure:bool, confidence:float, lines:int}
     *
     * @throws \RuntimeException when the picture cannot be read at all
     */
    public function readIdCard(string $imageData): array
    {
        $binary = self::binary();
        if ('' === $binary) {
            throw new \RuntimeException('Tesseract is not installed on the server');
        }

        try {
            $best = null;
            // A card held by hand is often rotated; stop as soon as one orientation reads well.
            foreach ([0, 90, 270, 180] as $degrees) {
                $prepared = $this->prepare($imageData, $degrees);
                if (null === $prepared) {
                    continue;
                }
                $lines = array_merge(
                    $this->tesseract($binary, $prepared, ['--psm', '6', '-l', self::LANGS]),
                    $this->tesseract($binary, $prepared, ['--psm', '11', '-l', self::LANGS]),
                    $this->tesseract($binary, $prepared, ['--psm', '6', '-l', 'eng', '-c', 'tessedit_char_whitelist='.self::MRZ_WHITELIST]),
                );
                $result = IdCardParser::parse($lines);
                $result['lines'] = \count($lines);
                if (null === $best || $result['confidence'] > $best['confidence']) {
                    $best = $result;
                }
                if ($best['confidence'] >= 0.7) {
                    break;
                }
            }
            if (null === $best) {
                throw new \RuntimeException('The picture could not be decoded');
            }

            return $best;
        } finally {
            // the picture never outlives the request (only the prepared grayscale copy is written,
            // and getTemporaryFile registers it for removal here)
            $this->tempManager->clean();
        }
    }

    /**
     * Normalise the picture for OCR and return the path of a temporary PNG.
     */
    private function prepare(string $imageData, int $degrees): ?string
    {
        $target = $this->tempManager->getTemporaryFile('.png');
        if (false === $target) {
            return null;
        }

        try {
            if (class_exists(\Imagick::class)) {
                $image = new \Imagick();
                // from memory: the format is detected from the content, and the original
                // bytes are never written to disk
                $image->readImageBlob($imageData);
                $image->setImageOrientation($image->getImageOrientation() ?: \Imagick::ORIENTATION_TOPLEFT);
                $image->autoOrient();
                if (0 !== $degrees) {
                    $image->rotateImage(new \ImagickPixel('black'), $degrees);
                }
                $w = $image->getImageWidth();
                $h = $image->getImageHeight();
                if (max($w, $h) > self::MAX_PX) {
                    $scale = self::MAX_PX / max($w, $h);
                    $image->resizeImage((int) ($w * $scale), (int) ($h * $scale), \Imagick::FILTER_LANCZOS, 1);
                }
                $image->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
                $image->normalizeImage();
                $image->contrastImage(true);
                $image->sharpenImage(0, 1);
                $image->setImageFormat('png');
                $image->writeImage($target);
                $image->clear();

                return $target;
            }

            // GD fallback
            $image = @imagecreatefromstring($imageData);
            if (false === $image) {
                return null;
            }
            if (0 !== $degrees) {
                $rotated = imagerotate($image, -$degrees, 0);
                if (false !== $rotated) {
                    imagedestroy($image);
                    $image = $rotated;
                }
            }
            $w = imagesx($image);
            $h = imagesy($image);
            if (max($w, $h) > self::MAX_PX) {
                $scale = self::MAX_PX / max($w, $h);
                $resized = imagescale($image, (int) ($w * $scale), (int) ($h * $scale));
                if (false !== $resized) {
                    imagedestroy($image);
                    $image = $resized;
                }
            }
            imagefilter($image, IMG_FILTER_GRAYSCALE);
            imagefilter($image, IMG_FILTER_CONTRAST, -20);
            imagepng($image, $target);
            imagedestroy($image);

            return $target;
        } catch (\Throwable $e) {
            $this->logger->warning("idregister: could not prepare the picture: ".$e->getMessage());

            return null;
        }
    }

    /**
     * @param list<string> $options
     *
     * @return list<string>
     */
    private function tesseract(string $binary, string $image, array $options): array
    {
        $command = array_merge([$binary, $image, 'stdout'], $options);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = ['OMP_THREAD_LIMIT' => '2', 'TMPDIR' => sys_get_temp_dir()];
        $process = @proc_open($command, $descriptors, $pipes, null, $env);
        if (!\is_resource($process)) {
            return [];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $deadline = microtime(true) + self::TIMEOUT;
        while (microtime(true) < $deadline) {
            $output .= stream_get_contents($pipes[1]) ?: '';
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(20000);
        }
        $output .= stream_get_contents($pipes[1]) ?: '';
        $error = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_terminate($process);
        proc_close($process);

        if ('' === trim($output) && '' !== trim($error)) {
            $this->logger->debug('idregister: tesseract said '.trim($error));
        }

        return array_values(array_filter(array_map('trim', explode("\n", $output)), static fn ($l) => '' !== $l));
    }
}
