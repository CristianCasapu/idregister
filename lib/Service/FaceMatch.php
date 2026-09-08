<?php

declare(strict_types=1);

namespace OCA\IdRegister\Service;

use OCA\IdRegister\AppInfo\Application;
use OCP\IAppConfig;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Compares the face on the document with the selfie.
 *
 * Both pictures are read in memory, turned into a 512-value descriptor by InsightFace and then
 * dropped; only the descriptor of the document photo lives on (in the visitor's session) so the
 * selfie can be compared against it.
 *
 * The thresholds come from measurements on a real library of 24,747 faces: two pictures of the
 * same person are 0.43–1.13 apart, two different people 1.27–1.49. A printed document photo is
 * harder than two selfies, so the default is a little more forgiving, with a middle band that
 * asks an administrator to look.
 */
final class FaceMatch
{
    public const VERDICT_MATCH = 'match';
    public const VERDICT_REVIEW = 'review';
    public const VERDICT_DIFFERENT = 'different';
    public const VERDICT_NO_FACE = 'no_face';
    /** the "selfie" is the photo printed on the document itself (re-photographed or uploaded) */
    public const VERDICT_COPY = 'copy';
    /** two different pictures of a live person never come this close; the same picture does */
    public const COPY_DISTANCE = 0.25;
    public const TIMEOUT = 180;

    public function __construct(
        private Settings $settings,
        private IAppConfig $appConfig,
        private ITempManager $tempManager,
        private LoggerInterface $logger,
    ) {}

    /**
     * Is the whole thing usable on this server? The answer needs a Python that really has
     * InsightFace, so it is checked once and remembered for an hour.
     */
    public function available(): bool
    {
        $binary = $this->pythonBinary();
        if ('' === $binary || !is_executable($binary) || !is_dir($this->insightfaceRoot().'/models')) {
            return false;
        }
        $cached = json_decode($this->appConfig->getValueString(Application::APP_ID, 'faceCheck', ''), true);
        if (\is_array($cached) && ($cached['binary'] ?? '') === $binary && (time() - (int) ($cached['time'] ?? 0)) < 3600) {
            return (bool) $cached['ok'];
        }

        $process = new Process([$binary, '-c', 'import insightface, onnxruntime, cv2'], null, ['HOME' => getenv('HOME') ?: '/tmp']);
        $process->setTimeout(60);
        $process->run();
        $ok = $process->isSuccessful();
        $this->appConfig->setValueString(Application::APP_ID, 'faceCheck', json_encode(['binary' => $binary, 'ok' => $ok, 'time' => time()]));
        if (!$ok) {
            $this->logger->warning('idregister: '.$binary.' cannot import insightface: '.trim($process->getErrorOutput()));
        }

        return $ok;
    }

    /** @return array{python:string, root:string, available:bool} */
    public function status(): array
    {
        return [
            'python' => $this->pythonBinary(),
            'root' => $this->insightfaceRoot(),
            'available' => $this->available(),
        ];
    }

    /**
     * Descriptor of the biggest face in a picture.
     *
     * @return array{ok:bool, vector:list<float>, faces:int, error:string}
     */
    public function describe(string $imageData, float $minSize = 0.04, bool $withPose = false): array
    {
        if (!$this->available()) {
            return ['ok' => false, 'vector' => [], 'faces' => 0, 'error' => 'insightface is not installed'];
        }
        $file = $this->tempManager->getTemporaryFile('.jpg');
        if (false === $file) {
            return ['ok' => false, 'vector' => [], 'faces' => 0, 'error' => 'no temporary file'];
        }
        file_put_contents($file, $imageData);

        try {
            $process = new Process(
                [$this->pythonBinary(), \dirname(__DIR__, 2).'/src/face_embed.py', $file, '--min-size', (string) $minSize],
                \dirname(__DIR__, 2),
                $this->environment() + ['FACE_POSE' => $withPose ? '1' : '0'],
            );
            $process->setTimeout(self::TIMEOUT);
            $process->run();
            if (!$process->isSuccessful()) {
                $this->logger->warning('idregister: the face reader failed: '.trim($process->getErrorOutput()));

                return ['ok' => false, 'vector' => [], 'faces' => 0, 'error' => 'the face reader failed'];
            }
            $data = json_decode(trim($process->getOutput()), true);
            if (!\is_array($data)) {
                return ['ok' => false, 'vector' => [], 'faces' => 0, 'error' => 'unreadable answer'];
            }

            return [
                'ok' => (bool) ($data['ok'] ?? false),
                'vector' => array_map('floatval', $data['vector'] ?? []),
                'faces' => (int) ($data['faces'] ?? 0),
                'error' => (string) ($data['error'] ?? ''),
                // [pitch, yaw, roll] in degrees, when the model gave them
                'pose' => \is_array($data['pose'] ?? null) ? array_map('floatval', $data['pose']) : null,
                // the face box, relative to the picture (0..1)
                'box' => \is_array($data['box'] ?? null) ? ['x' => (float) $data['box']['x'], 'y' => (float) $data['box']['y'], 'w' => (float) $data['box']['width'], 'h' => (float) $data['box']['height']] : null,
            ];
        } finally {
            @unlink($file);
            $this->tempManager->clean();
        }
    }

    /**
     * Compare a selfie with the descriptor taken from the document.
     *
     * @param list<float> $documentVector
     *
     * @return array{verdict:string, distance:?float, faces:int}
     */
    public function compare(array $documentVector, string $selfieData): array
    {
        if (0 === \count($documentVector)) {
            return ['verdict' => self::VERDICT_NO_FACE, 'distance' => null, 'faces' => 0];
        }
        // a selfie fills the frame, so the face must be a decent part of it
        $selfie = $this->describe($selfieData, 0.08);
        if (!$selfie['ok'] || 0 === \count($selfie['vector'])) {
            return ['verdict' => self::VERDICT_NO_FACE, 'distance' => null, 'faces' => $selfie['faces']];
        }

        $distance = self::distance($documentVector, $selfie['vector']);
        $match = (float) $this->settings->get('selfieMatchDistance');
        $review = (float) $this->settings->get('selfieReviewDistance');

        $verdict = match (true) {
            $distance < self::COPY_DISTANCE => self::VERDICT_COPY,
            $distance <= $match => self::VERDICT_MATCH,
            $distance <= $review => self::VERDICT_REVIEW,
            default => self::VERDICT_DIFFERENT,
        };

        return ['verdict' => $verdict, 'distance' => round($distance, 3), 'faces' => $selfie['faces']];
    }

    /** @param list<float> $a @param list<float> $b */
    public static function distance(array $a, array $b): float
    {
        if (\count($a) !== \count($b) || 0 === \count($a)) {
            return \PHP_FLOAT_MAX;
        }
        $sum = 0.0;
        foreach ($a as $i => $value) {
            $delta = $value - $b[$i];
            $sum += $delta * $delta;
        }

        return sqrt($sum);
    }

    /** The Python that has InsightFace: the administrator's choice, then Recognize's, then the usual places. */
    public function pythonBinary(): string
    {
        $own = trim((string) $this->settings->get('pythonBinary'));
        if ('' !== $own) {
            return $own;
        }
        // Recognize stores this as a lazy value
        foreach ([false, true] as $lazy) {
            $fromRecognize = trim($this->appConfig->getValueString('recognize', 'python_binary', '', $lazy));
            if ('' !== $fromRecognize && is_executable($fromRecognize)) {
                return $fromRecognize;
            }
        }
        $home = getenv('HOME') ?: '';
        foreach ([
            \dirname(\OC::$SERVERROOT).'/venv-recognize/bin/python',
            \OC::$SERVERROOT.'/venv-recognize/bin/python',
            $home.'/venv-recognize/bin/python',
            '/usr/bin/python3',
        ] as $candidate) {
            if ('' !== $candidate && is_executable($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    private function insightfaceRoot(): string
    {
        $own = trim((string) $this->settings->get('insightfaceRoot'));
        if ('' !== $own) {
            return $own;
        }
        foreach ([false, true] as $lazy) {
            $fromRecognize = trim($this->appConfig->getValueString('recognize', 'insightface.root', '', $lazy));
            if ('' !== $fromRecognize && is_dir($fromRecognize)) {
                return $fromRecognize;
            }
        }

        return (getenv('HOME') ?: '/root').'/.insightface';
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        return [
            'RECOGNIZE_INSIGHTFACE_ROOT' => $this->insightfaceRoot(),
            'RECOGNIZE_INSIGHTFACE_MODEL' => $this->appConfig->getValueString('recognize', 'insightface.model', '', true) ?: 'buffalo_l',
            'HOME' => getenv('HOME') ?: '/tmp',
            // the web worker usually cannot open /dev/nvidia*, and one picture is quick on the CPU
            'RECOGNIZE_GPU' => (@is_readable('/dev/nvidiactl') && @is_readable('/dev/nvidia0')) ? 'true' : 'false',
            'TMPDIR' => (string) $this->tempManager->getTempBaseDir(),
        ];
    }
}
