<?php

declare(strict_types=1);

namespace OCA\IdRegister\BackgroundJobs;

use OCA\IdRegister\Service\FaceMatch;
use OCA\IdRegister\Service\Ocr;
use OCA\IdRegister\Service\PythonEnv;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/** Installs the reader and the face models, queued from the administration page. */
final class InstallJob extends QueuedJob
{
    public function __construct(ITimeFactory $time, private PythonEnv $env, private Ocr $ocr, private FaceMatch $faceMatch, private LoggerInterface $logger)
    {
        parent::__construct($time);
        $this->setAllowParallelRuns(false);
    }

    protected function run($argument): void
    {
        @set_time_limit(0);

        try {
            $this->env->install();
        } catch (\Throwable $e) {
            // already recorded in the install state for the administration page
            $this->logger->debug('idregister: install job: '.$e->getMessage());
        }
        $this->ocr->forgetEngineCheck();
        $this->faceMatch->forgetCheck();
    }
}
