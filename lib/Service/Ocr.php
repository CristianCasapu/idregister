<?php

declare(strict_types=1);

namespace OCA\IdRegister\Service;

use OCA\IdRegister\AppInfo\Application;
use OCP\IAppConfig;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Reads the text of an identity document. Two engines: RapidOCR (neural detection and
 * recognition on ONNX Runtime, the same kind of reader as ML Kit in NecMat — it reads a
 * photographed card reliably) when it is installed in the Python environment, and Tesseract
 * as the fallback. The picture is only ever a temporary file: normalised, read, deleted
 * immediately afterwards — nothing about the card is stored.
 */
final class Ocr
{
    public const MAX_PX = 2200;
    public const LANGS = 'ron+eng';
    /** the MRZ uses OCR-B and only these characters */
    public const MRZ_WHITELIST = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789<';
    public const TIMEOUT = 60;
    /** a live frame is enlarged to this size before reading (small letters read badly) */
    public const FRAME_MIN_PX = 1600;

    public const ENGINE_RAPIDOCR = 'rapidocr';
    public const ENGINE_TESSERACT = 'tesseract';
    /** RapidOCR: how sure a line has to be for a printed name to count as confirmed without the MRZ */
    public const SURE_CONFIDENCE = 0.9;

    public function __construct(
        private ITempManager $tempManager,
        private LoggerInterface $logger,
        private FaceMatch $faceMatch,
        private IAppConfig $appConfig,
    ) {}

    /**
     * Which engine reads the documents, and whether reading works at all.
     *
     * @return array{ok:bool, engine:string, python:string, rapidocr:bool, tesseract:array{ok:bool, version:string, languages:list<string>, missing:list<string>}, hint:string}
     */
    public function engineStatus(): array
    {
        $tesseract = self::status();
        $python = $this->faceMatch->pythonBinary();
        $rapid = $this->rapidAvailable($python);
        $engine = $rapid ? self::ENGINE_RAPIDOCR : ($tesseract['ok'] ? self::ENGINE_TESSERACT : '');

        return [
            'ok' => '' !== $engine,
            'engine' => $engine,
            'python' => $python,
            'rapidocr' => $rapid,
            'tesseract' => $tesseract,
            'hint' => $rapid ? '' : 'occ idregister:install-ocr',
        ];
    }

    /** Is RapidOCR importable by this Python? (checked at most once an hour) */
    private function rapidAvailable(string $python): bool
    {
        if ('' === $python || !is_executable($python)) {
            return false;
        }
        $cached = json_decode($this->appConfig->getValueString(Application::APP_ID, 'ocrCheck', ''), true);
        if (\is_array($cached) && ($cached['python'] ?? '') === $python && (time() - (int) ($cached['time'] ?? 0)) < 3600) {
            return (bool) $cached['ok'];
        }
        $process = new Process([$python, '-c', 'import rapidocr_onnxruntime, cv2, numpy']);
        $process->setTimeout(60);
        try {
            $process->run();
            $ok = $process->isSuccessful();
        } catch (\Throwable $e) {
            $ok = false;
        }
        $this->appConfig->setValueString(Application::APP_ID, 'ocrCheck', json_encode(['python' => $python, 'ok' => $ok, 'time' => time()]));

        return $ok;
    }

    /** Forget the cached engine check (after an installation) */
    public function forgetEngineCheck(): void
    {
        $this->appConfig->deleteKey(Application::APP_ID, 'ocrCheck');
    }

    /**
     * RapidOCR on one prepared picture.
     *
     * @return array{lines:list<string>, boxes:list<array{x:float,y:float,w:float,h:float}>, confs:list<float>, width:int, height:int}|null null when the engine is missing or failed
     */
    private function rapid(string $imagePath): ?array
    {
        $python = $this->faceMatch->pythonBinary();
        if (!$this->rapidAvailable($python)) {
            return null;
        }
        $process = new Process(
            [$python, \dirname(__DIR__, 2).'/src/ocr_frame.py', $imagePath],
            \dirname(__DIR__, 2),
            ['TMPDIR' => (string) $this->tempManager->getTempBaseDir(), 'OMP_NUM_THREADS' => '2'],
        );
        $process->setTimeout(self::TIMEOUT);
        try {
            $process->run();
        } catch (\Throwable $e) {
            $this->logger->warning('idregister: RapidOCR failed: '.$e->getMessage());

            return null;
        }
        if (!$process->isSuccessful()) {
            $lines = array_slice(array_filter(explode("\n", trim($process->getErrorOutput()))), -2);
            $this->logger->warning('idregister: RapidOCR failed: '.implode(' | ', $lines));

            return null;
        }
        $out = json_decode(trim($process->getOutput()), true);
        if (!\is_array($out) || !isset($out['lines'])) {
            return null;
        }
        $lines = [];
        $boxes = [];
        $confs = [];
        foreach ($out['lines'] as $line) {
            $lines[] = (string) $line['text'];
            $boxes[] = ['x' => (float) $line['x'], 'y' => (float) $line['y'], 'w' => (float) $line['w'], 'h' => (float) $line['h']];
            $confs[] = (float) $line['conf'];
        }

        return ['lines' => $lines, 'boxes' => $boxes, 'confs' => $confs, 'width' => (int) $out['width'], 'height' => (int) $out['height']];
    }

    /**
     * The picture as the neural reader likes it: colour, upright, at most MAX_PX, as a JPEG.
     */
    private function prepareColour(string $imageData, int $degrees): ?string
    {
        if (!class_exists(\Imagick::class)) {
            return null;
        }
        $target = $this->tempManager->getTemporaryFile('.jpg');
        if (false === $target) {
            return null;
        }

        try {
            $image = new \Imagick();
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
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(92);
            $image->writeImage($target);
            $image->clear();

            return $target;
        } catch (\Throwable $e) {
            $this->logger->warning('idregister: could not prepare the picture: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Printed names count as confirmed without the machine readable zone when the neural reader
     * is sure of the lines they came from (the new electronic card has no MRZ on its front).
     *
     * @param list<string> $lines
     * @param list<float> $confs
     */
    private static function confidentName(string $name, array $lines, array $confs): bool
    {
        $wanted = self::letters($name);
        if ('' === $wanted) {
            return false;
        }
        foreach ($lines as $i => $line) {
            if (self::letters($line) === $wanted && ($confs[$i] ?? 0.0) >= self::SURE_CONFIDENCE) {
                return true;
            }
        }

        return false;
    }

    private static function letters(string $s): string
    {
        return preg_replace('/[^a-z]/', '', mb_strtolower(IdCardParser::stripDiacritics($s))) ?? '';
    }

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
     * @return array{type:string, surname:string, givenNames:string, cnp:string, cnpSure:bool, birthDate:string, confidence:float, lines:int}
     *
     * @throws \RuntimeException when the picture cannot be read at all
     */
    public function readDocument(string $imageData): array
    {
        $binary = self::binary();
        if ('' === $binary && !$this->rapidAvailable($this->faceMatch->pythonBinary())) {
            throw new \RuntimeException('No text recognition engine is installed on the server');
        }

        try {
            $best = null;
            // A card held by hand is often rotated; stop as soon as one orientation reads well.
            foreach ([0, 90, 270, 180] as $degrees) {
                $colour = $this->prepareColour($imageData, $degrees);
                $rapid = null !== $colour ? $this->rapid($colour) : null;
                if (null !== $rapid) {
                    $result = DocumentReader::parse($rapid['lines']);
                    $result['lines'] = \count($rapid['lines']);
                    if (DocumentReader::TYPE_ID_CARD === $result['type'] && !$result['nameSure'] && $result['cnpSure']
                        && self::confidentName($result['surname'], $rapid['lines'], $rapid['confs'])
                        && self::confidentName($result['givenNames'], $rapid['lines'], $rapid['confs'])) {
                        $result['nameSure'] = true;
                    }
                    if (null === $best || $result['confidence'] > $best['confidence']) {
                        $best = $result;
                    }
                    if ($best['confidence'] >= 0.7) {
                        break;
                    }
                    continue;
                }
                $prepared = $this->prepare($imageData, $degrees);
                if (null === $prepared) {
                    continue;
                }
                $lines = array_merge(
                    $this->tesseract($binary, $prepared, ['--psm', '6', '-l', self::LANGS]),
                    $this->tesseract($binary, $prepared, ['--psm', '11', '-l', self::LANGS]),
                    $this->tesseract($binary, $prepared, ['--psm', '6', '-l', 'eng', '-c', 'tessedit_char_whitelist='.self::MRZ_WHITELIST]),
                );
                $result = DocumentReader::parse($lines);
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
     * @deprecated use readDocument(); kept so older callers keep working
     *
     * @return array{type:string, surname:string, givenNames:string, cnp:string, cnpSure:bool, birthDate:string, confidence:float, lines:int}
     */
    public function readIdCard(string $imageData): array
    {
        return $this->readDocument($imageData);
    }

    /**
     * Normalise the picture for OCR and return the path of a temporary PNG.
     */
    private function prepare(string $imageData, int $degrees, int $minPx = 0): ?string
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
                } elseif ($minPx > 0 && max($w, $h) < $minPx) {
                    $scale = $minPx / max($w, $h);
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
     * Read one frame of the live camera (the card, cropped to the guide by the phone).
     *
     * One pass over the frame gives the lines with their positions (the phone draws them and the
     * guidance needs them); when the name or the personal number is still missing, the machine
     * readable zone at the bottom is read again, enlarged and restricted to its alphabet.
     * Nothing is rotated: the phone holds the card the way the guide shows it.
     *
     * @return array{lines:list<string>, boxes:list<array{x:float,y:float,w:float,h:float}>, confs:list<float>, width:int, height:int}
     */
    public function readFrame(string $imageData): array
    {
        $binary = self::binary();
        if ('' === $binary && !$this->rapidAvailable($this->faceMatch->pythonBinary())) {
            throw new \RuntimeException('No text recognition engine is installed on the server');
        }

        try {
            $confs = [];
            $colour = $this->prepareColour($imageData, 0);
            $rapid = null !== $colour ? $this->rapid($colour) : null;
            if (null !== $rapid) {
                $lines = $rapid['lines'];
                $boxes = $rapid['boxes'];
                $confs = $rapid['confs'];
                $width = $rapid['width'];
                $height = $rapid['height'];
                $prepared = null;
            } else {
                $prepared = $this->prepare($imageData, 0, self::FRAME_MIN_PX);
                if (null === $prepared) {
                    throw new \RuntimeException('The frame could not be decoded');
                }
                [$width, $height] = self::sizeOf($prepared);
                $tsv = $this->run($binary, $prepared, ['--psm', '6', '-l', self::LANGS, 'tsv']);
                [$lines, $boxes] = self::linesFromTsv($tsv, $width, $height);
            }

            $parsed = IdCardParser::parse($lines);
            // The machine readable zone (old card) read once more, enlarged and restricted to its
            // alphabet — only when there is one and it did not read well; the new electronic card
            // has none on its front, and the extra pass would cost a second on every frame.
            $hasMrz = null === $rapid || \count(array_filter($lines, static fn ($l) => str_contains($l, '<<') || str_contains(strtoupper($l), 'IDROU'))) > 0;
            if ('' !== $binary && $hasMrz && (!$parsed['cnpSure'] || '' === $parsed['surname'] || '' === $parsed['givenNames'])) {
                $prepared ??= $this->prepare($imageData, 0, self::FRAME_MIN_PX);
                [$pw, $ph] = null !== $prepared ? self::sizeOf($prepared) : [0, 0];
                $mrz = null !== $prepared ? $this->cropBottom($prepared, $pw, $ph, 0.36, 2.0) : null;
                if (null !== $mrz) {
                    $more = $this->tesseract($binary, $mrz, ['--psm', '6', '-l', 'eng', '-c', 'tessedit_char_whitelist='.self::MRZ_WHITELIST]);
                    foreach ($more as $line) {
                        if (!\in_array($line, $lines, true)) {
                            $lines[] = $line;
                        }
                    }
                }
            }

            return ['lines' => $lines, 'boxes' => $boxes, 'confs' => $confs, 'width' => $width, 'height' => $height];
        } finally {
            $this->tempManager->clean();
        }
    }

    /** @return array{0:int,1:int} */
    private static function sizeOf(string $path): array
    {
        $size = @getimagesize($path);

        return [(int) ($size[0] ?? 0), (int) ($size[1] ?? 0)];
    }

    /** The bottom band of a prepared frame (the machine readable zone), enlarged. */
    private function cropBottom(string $path, int $width, int $height, float $fraction, float $scale): ?string
    {
        if ($width < 2 || $height < 2 || !class_exists(\Imagick::class)) {
            return null;
        }
        $target = $this->tempManager->getTemporaryFile('.png');
        if (false === $target) {
            return null;
        }

        try {
            $image = new \Imagick($path);
            $h = max(1, (int) round($height * $fraction));
            $image->cropImage($width, $h, 0, $height - $h);
            $image->setImagePage(0, 0, 0, 0);
            $image->resizeImage((int) round($width * $scale), (int) round($h * $scale), \Imagick::FILTER_LANCZOS, 1);
            $image->writeImage($target);
            $image->clear();

            return $target;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Lines of text and their boxes (relative to the image) from Tesseract's TSV output:
     * the words of one line are joined in reading order, the box is their union.
     *
     * @return array{0:list<string>, 1:list<array{x:float,y:float,w:float,h:float}>}
     */
    public static function linesFromTsv(string $tsv, int $width, int $height): array
    {
        $groups = [];
        foreach (explode("\n", $tsv) as $row) {
            $cols = explode("\t", $row);
            if (\count($cols) < 12 || '5' !== $cols[0]) {
                continue;
            }
            $text = trim($cols[11]);
            if ('' === $text) {
                continue;
            }
            $key = $cols[2].'.'.$cols[3].'.'.$cols[4];
            $groups[$key] ??= ['words' => [], 'l' => PHP_INT_MAX, 't' => PHP_INT_MAX, 'r' => 0, 'b' => 0];
            $left = (int) $cols[6];
            $top = (int) $cols[7];
            $groups[$key]['words'][] = [$left, $text];
            $groups[$key]['l'] = min($groups[$key]['l'], $left);
            $groups[$key]['t'] = min($groups[$key]['t'], $top);
            $groups[$key]['r'] = max($groups[$key]['r'], $left + (int) $cols[8]);
            $groups[$key]['b'] = max($groups[$key]['b'], $top + (int) $cols[9]);
        }
        // reading order: top to bottom, then left to right
        uasort($groups, static fn ($a, $b) => [$a['t'], $a['l']] <=> [$b['t'], $b['l']]);
        $lines = [];
        $boxes = [];
        foreach ($groups as $g) {
            usort($g['words'], static fn ($a, $b) => $a[0] <=> $b[0]);
            $lines[] = implode(' ', array_column($g['words'], 1));
            $boxes[] = [
                'x' => $width > 0 ? round($g['l'] / $width, 4) : 0.0,
                'y' => $height > 0 ? round($g['t'] / $height, 4) : 0.0,
                'w' => $width > 0 ? round(($g['r'] - $g['l']) / $width, 4) : 0.0,
                'h' => $height > 0 ? round(($g['b'] - $g['t']) / $height, 4) : 0.0,
            ];
        }

        return [$lines, $boxes];
    }

    /**
     * @param list<string> $options
     *
     * @return list<string>
     */
    private function tesseract(string $binary, string $image, array $options): array
    {
        $output = $this->run($binary, $image, $options);

        return array_values(array_filter(array_map('trim', explode("\n", $output)), static fn ($l) => '' !== $l));
    }

    /**
     * @param list<string> $options
     *
     * @return string raw output of Tesseract
     */
    private function run(string $binary, string $image, array $options): string
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

        return $output;
    }
}
