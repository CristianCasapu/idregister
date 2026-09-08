<?php

declare(strict_types=1);

namespace OCA\IdRegister\SetupChecks;

use OCA\IdRegister\Service\Ocr;
use OCA\IdRegister\Service\Settings;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/** Tells the administrator when the card reader cannot work. */
final class TesseractCheck implements ISetupCheck
{
    public function __construct(private IL10N $l, private Settings $settings) {}

    public function getCategory(): string
    {
        return 'security';
    }

    public function getName(): string
    {
        return $this->l->t('Sign up with ID: text recognition');
    }

    public function run(): SetupResult
    {
        $status = Ocr::status();
        if (!$this->settings->get('registrationOpen')) {
            return SetupResult::success($this->l->t('Registration with an identity card is switched off.'));
        }
        if ('' === $status['version']) {
            return SetupResult::error($this->l->t('Tesseract is not installed, so identity cards cannot be read. Install it with: sudo apt install tesseract-ocr tesseract-ocr-ron'));
        }
        if (\count($status['missing']) > 0) {
            return SetupResult::warning($this->l->t('Tesseract %1$s is installed but these language packs are missing: %2$s. Install them with: sudo apt install %2$s', [$status['version'], implode(' ', $status['missing'])]));
        }

        return SetupResult::success($this->l->t('Tesseract %1$s with the languages %2$s.', [$status['version'], implode(', ', $status['languages'])]));
    }
}
