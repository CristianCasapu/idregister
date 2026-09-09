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
    public function __construct(private IL10N $l, private Settings $settings, private Ocr $ocr) {}

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
        $status = $this->ocr->engineStatus();
        if (!$this->settings->get('registrationOpen')) {
            return SetupResult::success($this->l->t('Registration with an identity card is switched off.'));
        }
        if ($status['rapidocr']) {
            return SetupResult::success($this->l->t('Documents are read with RapidOCR (%s).', [$status['python']]));
        }
        $tesseract = $status['tesseract'];
        if (!$tesseract['ok']) {
            return SetupResult::error($this->l->t('No text recognition is installed, so documents cannot be read. Open Administration settings › Sign up with ID and press "Install the reader" (or run occ idregister:install-ocr).'));
        }

        return SetupResult::warning($this->l->t('Documents are read with Tesseract %s, which reads photographed cards poorly. Open Administration settings › Sign up with ID and press "Install the reader" (or run occ idregister:install-ocr).', [$tesseract['version']]));
    }
}
