<?php

declare(strict_types=1);

namespace OCA\IdRegister\Service;

use OCA\IdRegister\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;

/**
 * Administration settings of the registration form, with their defaults.
 */
final class Settings
{
    /** What each number may be: [min, max]. 0 = "no limit" / "no minimum" where it says so in the admin page. */
    public const RANGES = [
        'expiryHours' => [1, 720],
        'minConfidence' => [0.0, 1.0],
        'minAge' => [0, 120],
        'minPasswordLength' => [8, 128],
        'maxAccounts' => [0, 1000000],
        'selfieMatchDistance' => [0.1, 3.0],
        'selfieReviewDistance' => [0.1, 3.0],
    ];

    public const DEFAULTS = [
        'registrationOpen' => false,
        'requireApproval' => false,
        // groups the new account joins, comma separated
        'defaultGroups' => '',
        'quota' => '',
        // e-mail domains: empty = anything goes; otherwise a comma separated list
        'allowedDomains' => '',
        'blockedDomains' => '',
        // how long an unconfirmed registration lives (hours)
        'expiryHours' => 48,
        // how sure the card reader must be before the name is accepted (0..1)
        'minConfidence' => 0.55,
        // a card can only be used once
        'oneAccountPerCard' => true,
        // the identity card must carry a personal number that passes its check digit
        'requireValidCnp' => true,
        // the name has to be confirmed twice (printed and machine readable zone), so a misread
        // name is never locked onto an account
        'requireConfirmedName' => true,
        // youngest age accepted, read from the personal number (0 = no limit)
        'minAge' => 0,
        'requirePhone' => true,
        'minPasswordLength' => 10,
        // stop after this many accounts have been created this way (0 = no limit)
        'maxAccounts' => 0,
        // shown next to the consent checkbox
        'termsUrl' => '',
        // e-mail the administrators on every new registration
        'notifyAdmins' => false,
        // the selfie has to show the person on the document
        'requireSelfie' => true,
        'requirePhysical' => true, // refuse copies and screens; ask for a tilt when unsure
        'requireValidDocument' => true, // an expired card is refused; unreadable expiry → administrator review
        'chipEnabled' => false, // /api/chip (the phone app's NFC step): off until the chip data is verified by signature
        'androidAppUrl' => 'https://github.com/CristianCasapu/idregister-android/releases/latest', // suggested when the web scan struggles; empty = never
        // measured on a real library: same person 0.43–1.13, different people 1.27–1.49
        'selfieMatchDistance' => 1.15,
        'selfieReviewDistance' => 1.30,
        // which documents are accepted
        'acceptIdCard' => true,
        'acceptDrivingLicence' => true,
        // only when the automatic detection picks the wrong Python
        'pythonBinary' => '',
        'insightfaceRoot' => '',
        // registration only from a phone or tablet; a desktop gets a QR code
        'mobileOnly' => true,
        // express: the account is created right after the document (and the selfie), with a random
        // user name and password kept by the browser; e-mail, phone and nickname are added in the profile
        'expressMode' => true,
    ];

    public function __construct(private IAppConfig $config) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $raw = $this->config->getValueString(Application::APP_ID, $key, (string) (\is_bool($default) ? ($default ? '1' : '0') : $default));
            $out[$key] = match (true) {
                \is_bool($default) => '1' === $raw || 'true' === $raw,
                \is_int($default) => (int) $raw,
                \is_float($default) => (float) $raw,
                default => $raw,
            };
        }

        return $out;
    }

    /** @param array<string, mixed> $values */
    public function set(array $values): array
    {
        foreach ($values as $key => $value) {
            if (!\array_key_exists($key, self::DEFAULTS)) {
                continue;
            }
            $default = self::DEFAULTS[$key];
            [$min, $max] = self::RANGES[$key] ?? [0, PHP_INT_MAX];
            $raw = match (true) {
                \is_bool($default) => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
                \is_int($default) => (string) (int) min($max, max($min, (int) $value)),
                \is_float($default) => (string) min((float) $max, max((float) $min, (float) $value)),
                default => trim((string) $value),
            };
            $this->config->setValueString(Application::APP_ID, $key, $raw);
        }

        return $this->all();
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /** @return list<string> the groups a new account joins */
    public function groups(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->get('defaultGroups')))));
    }

    /**
     * Secret used to hash the personal number. Generated once, never leaves the server:
     * without it the hashes cannot be linked back to a personal number.
     */
    public function cnpSecret(): string
    {
        $secret = $this->config->getValueString(Application::APP_ID, 'cnpSecret', '');
        if ('' === $secret) {
            $secret = \OCP\Server::get(ISecureRandom::class)->generate(48, ISecureRandom::CHAR_ALPHANUMERIC);
            $this->config->setValueString(Application::APP_ID, 'cnpSecret', $secret, sensitive: true);
        }

        return $secret;
    }

    /** Is this e-mail address allowed by the domain rules? */
    public function emailAllowed(string $email): bool
    {
        $domain = mb_strtolower(substr(strrchr($email, '@') ?: '', 1));
        if ('' === $domain) {
            return false;
        }
        $split = static fn (string $list): array => array_values(array_filter(array_map(
            static fn ($d) => mb_strtolower(trim($d, " \t\n\r\0\x0B.@")),
            preg_split('/[,;\s]+/', $list) ?: [],
        )));

        $blocked = $split((string) $this->get('blockedDomains'));
        foreach ($blocked as $entry) {
            if ('' !== $entry && ($domain === $entry || str_ends_with($domain, '.'.$entry))) {
                return false;
            }
        }
        $allowed = $split((string) $this->get('allowedDomains'));
        if (0 === \count($allowed)) {
            return true;
        }
        foreach ($allowed as $entry) {
            if ('' !== $entry && ($domain === $entry || str_ends_with($domain, '.'.$entry))) {
                return true;
            }
        }

        return false;
    }
}
