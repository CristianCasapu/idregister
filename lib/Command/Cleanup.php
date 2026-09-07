<?php

declare(strict_types=1);

namespace OCA\IdRegister\Command;

use OCA\IdRegister\Service\Registration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class Cleanup extends Command
{
    public function __construct(private Registration $registration)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('idregister:cleanup')
            ->setDescription('Remove registrations whose e-mail address was never confirmed, together with their disabled accounts')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $removed = $this->registration->cleanup();
        $output->writeln("<info>{$removed} unconfirmed registrations removed</info>");

        return 0;
    }
}
