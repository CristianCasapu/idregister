<?php

declare(strict_types=1);

namespace OCA\IdRegister\Command;

use OCA\IdRegister\Service\Ocr;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class Scan extends Command
{
    public function __construct(private Ocr $ocr)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('idregister:scan')
            ->setDescription('Read an identity card from a picture and print what the registration form would see (nothing is stored)')
            ->addArgument('file', InputArgument::REQUIRED, 'Path of the picture')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = (string) $input->getArgument('file');
        if (!is_readable($file)) {
            $output->writeln("<error>Cannot read {$file}</error>");

            return 1;
        }
        $start = microtime(true);
        $card = $this->ocr->readIdCard((string) file_get_contents($file));
        $output->writeln('surname:     '.($card['surname'] ?: '-').($card['surnameSure'] ? ' (confirmed)' : ''));
        $output->writeln('given names: '.($card['givenNames'] ?: '-').($card['givenSure'] ? ' (confirmed)' : ''));
        $output->writeln('personal no: '.($card['cnpSure'] ? 'valid' : 'not read'));
        $output->writeln('confidence:  '.$card['confidence']);
        $output->writeln(sprintf('read in %.1f s', microtime(true) - $start));

        return $card['confidence'] > 0 ? 0 : 1;
    }
}
