<?php

declare(strict_types=1);

namespace OCA\IdRegister\Cron;

use OCA\IdRegister\Service\Handoff;
use OCA\IdRegister\Service\Registration;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/** Removes registrations that were never confirmed (and their disabled accounts). */
final class CleanupJob extends TimedJob
{
    public function __construct(ITimeFactory $time, private Registration $registration, private Handoff $handoff, private LoggerInterface $logger)
    {
        parent::__construct($time);
        $this->setInterval(3600);
    }

    protected function run(mixed $argument): void
    {
        try {
            $this->handoff->purge();
            $removed = $this->registration->cleanup();
            if ($removed > 0) {
                $this->logger->info('idregister: '.$removed.' unconfirmed registrations removed');
            }
        } catch (\Throwable $e) {
            $this->logger->warning('idregister: cleanup failed', ['exception' => $e]);
        }
    }
}
