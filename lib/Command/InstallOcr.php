<?php

declare(strict_types=1);

namespace OCA\IdRegister\Command;

use OCA\IdRegister\Service\FaceMatch;
use OCA\IdRegister\Service\Ocr;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Installs RapidOCR (neural text recognition, ONNX Runtime) into the Python environment the
 * app uses — the one of the Recognize fork by default — so documents are read the way NecMat
 * reads them, instead of with Tesseract.
 */
final class InstallOcr extends Command
{
    public function __construct(private Ocr $ocr, private FaceMatch $faceMatch)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('idregister:install-ocr')
            ->setDescription('Install RapidOCR (neural text recognition) into the Python environment used by Sign up with ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $python = $this->faceMatch->pythonBinary();
        if ('' === $python || !is_executable($python)) {
            $output->writeln('<error>No usable Python found. Set "pythonBinary" in the admin settings (a virtual environment you can write to).</error>');

            return 1;
        }
        $output->writeln('Python: '.$python);
        $process = new Process([$python, '-m', 'pip', 'install', '--upgrade', 'rapidocr_onnxruntime']);
        $process->setTimeout(1800);
        $process->run(function (string $type, string $buffer) use ($output): void {
            $output->write($buffer);
        });
        $this->ocr->forgetEngineCheck();
        $status = $this->ocr->engineStatus();
        if (!$status['rapidocr']) {
            $output->writeln('<error>RapidOCR could not be imported after the installation.</error>');

            return 1;
        }
        $output->writeln('<info>RapidOCR is installed; documents are now read with it.</info>');

        return 0;
    }
}
