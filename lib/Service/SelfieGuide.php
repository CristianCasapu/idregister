<?php

declare(strict_types=1);

namespace OCA\IdRegister\Service;

use OCP\IL10N;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * The automatic selfie: a small frame comes in every half second, the face is found on it and
 * the visitor is told what to do (come closer, move back, centre the face, hold still). Once a
 * frame is "good", the page or the app takes the real selfie by itself. The frame is dropped
 * right after; nothing is kept.
 *
 * The frame is the area around the oval as the page cuts it (1.35 × the oval horizontally,
 * 1.25 × vertically), so the oval sits at a known place in it.
 */
final class SelfieGuide
{
    private const TIMEOUT = 20;
    /** the oval inside the frame, relative to its width/height */
    private const OVAL_CX = 0.5;
    private const OVAL_CY = 0.5;
    private const OVAL_RX = 0.5 / 1.35;
    private const OVAL_RY = 0.5 / 1.25;
    /** how far the head must turn (degrees of yaw) to count as a turn */
    private const TURN_DEGREES = 18.0;

    public function __construct(
        private FaceMatch $faceMatch,
        private ITempManager $tempManager,
        private IL10N $l,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array{status:string, level:int, good:bool, turned?:bool, face:?array{x:float,y:float,w:float,h:float}}
     */
    public function guide(string $jpeg, string $phase = 'front'): array
    {
        if ('turn' === $phase) {
            // the head turned: the yaw from the 3D landmarks of the face model (a profile cascade fires on
            // frontal faces too, so it is no proof); about two seconds per frame, only during this phase
            $described = $this->faceMatch->describe($jpeg, 0.08, true);
            $pose = $described['pose'] ?? null;
            $yaw = \is_array($pose) && isset($pose[1]) ? abs((float) $pose[1]) : 0.0;
            $turned = \count($described['vector']) > 0 && $yaw >= self::TURN_DEGREES;
            $face = null;
            if ($turned) {
                return ['status' => $this->l->t('Good — now look at the camera again'), 'level' => 2, 'good' => false, 'turned' => true, 'face' => $face];
            }

            return ['status' => $this->l->t('Turn your head a little to the left or to the right'), 'level' => 1, 'good' => false, 'turned' => false, 'face' => $face];
        }
        $found = $this->detect($jpeg);
        $face = $found['face'];
        if (null === $face) {
            return ['status' => $this->l->t('Put your face inside the oval'), 'level' => 0, 'good' => false, 'face' => null];
        }
        $cx = $face['x'] + $face['w'] / 2;
        $cy = $face['y'] + $face['h'] / 2;
        $off = sqrt((($cx - self::OVAL_CX) / self::OVAL_RX) ** 2 + (($cy - self::OVAL_CY) / self::OVAL_RY) ** 2);
        // the cascade's box is brows-to-chin: about 0.6 of the oval's width when the head fills it
        $size = $face['w'] / (2 * self::OVAL_RX);
        if ($size < 0.42) {
            return ['status' => $this->l->t('Come closer'), 'level' => 1, 'good' => false, 'face' => $face];
        }
        if ($size > 0.95) {
            return ['status' => $this->l->t('Move back a little'), 'level' => 1, 'good' => false, 'face' => $face];
        }
        if ($off > 0.45) {
            return ['status' => $this->l->t('Centre your face in the oval'), 'level' => 1, 'good' => false, 'face' => $face];
        }

        return ['status' => $this->l->t('Hold still …'), 'level' => 2, 'good' => true, 'face' => $face];
    }

    /** @return array{face: ?array{x:float,y:float,w:float,h:float}, profile: float} */
    private function detect(string $jpeg): array
    {
        $none = ['face' => null, 'profile' => 0.0];
        $file = $this->tempManager->getTemporaryFile('.jpg');
        if (false === $file) {
            return $none;
        }
        file_put_contents($file, $jpeg);

        try {
            $process = new Process(
                [$this->faceMatch->pythonBinary(), \dirname(__DIR__, 2).'/src/face_guide.py', $file],
                \dirname(__DIR__, 2),
                ['TMPDIR' => (string) $this->tempManager->getTempBaseDir(), 'OMP_NUM_THREADS' => '1'],
            );
            $process->setTimeout(self::TIMEOUT);
            $process->run();
            if (!$process->isSuccessful()) {
                $this->logger->warning('idregister: the face guide failed: '.trim($process->getErrorOutput()));

                return $none;
            }
            $out = json_decode(trim($process->getOutput()), true);
            $faces = \is_array($out) && \is_array($out['faces'] ?? null) ? $out['faces'] : [];
            $profile = \is_array($out) ? (float) ($out['profile'] ?? 0) : 0.0;
            if (0 === \count($faces)) {
                return ['face' => null, 'profile' => $profile];
            }
            $f = $faces[0];

            return ['face' => ['x' => (float) $f['x'], 'y' => (float) $f['y'], 'w' => (float) $f['w'], 'h' => (float) $f['h']], 'profile' => $profile];
        } finally {
            @unlink($file);
            $this->tempManager->clean();
        }
    }
}
