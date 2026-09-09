<?php

declare(strict_types=1);

namespace OCA\IdRegister\Command;

use OCA\IdRegister\Service\Devices;
use OCA\IdRegister\Service\Handoff;
use OCA\IdRegister\Service\Registration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class Cleanup extends Command
{
    public function __construct(
        private Registration $registration,
        private Devices $devices,
        private Handoff $handoff,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('idregister:cleanup')
            ->setDescription('Remove registrations whose e-mail address was never confirmed together with their disabled accounts, and everything else that is past its end: hand-offs, pairing codes and sign-in requests')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $removed = $this->registration->cleanup();
        $output->writeln("<info>{$removed} unconfirmed registrations removed</info>");
        // the same sweep the hourly job makes
        $this->handoff->purge();
        $this->devices->forgetExpired();
        $output->writeln('<info>hand-offs, pairing codes and sign-in requests past their end removed</info>');

        return 0;
    }
}
