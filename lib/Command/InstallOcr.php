<?php

declare(strict_types=1);

namespace OCA\IdRegister\Command;

use OCA\IdRegister\Service\FaceMatch;
use OCA\IdRegister\Service\Ocr;
use OCA\IdRegister\Service\PythonEnv;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** The same installation the administration page offers, for people who prefer a terminal. */
final class InstallOcr extends Command
{
    public function __construct(private PythonEnv $env, private Ocr $ocr, private FaceMatch $faceMatch)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('idregister:install-ocr')
            ->setDescription('Install the document reader (RapidOCR) and the face models into the app\'s own Python environment in the data directory')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'remove the environment instead');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ((bool) $input->getOption('remove')) {
            $this->env->remove();
            $this->ocr->forgetEngineCheck();
            $this->faceMatch->forgetCheck();
            $output->writeln('Removed '.$this->env->dir());

            return 0;
        }
        if ($this->env->isInstalled()) {
            $output->writeln('<info>Already installed: '.$this->env->python().'</info> (use --remove first for a fresh one)');

            return 0;
        }

        try {
            $this->env->install(function (string $line) use ($output): void {
                $output->writeln($line);
            });
        } catch (\RuntimeException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return 1;
        }
        $this->ocr->forgetEngineCheck();
        $this->faceMatch->forgetCheck();
        $status = $this->ocr->engineStatus();
        $output->writeln($status['rapidocr'] ? '<info>Documents are read with RapidOCR; face matching is ready.</info>' : '<error>RapidOCR could not be imported after the installation.</error>');

        return $status['rapidocr'] ? 0 : 1;
    }
}
