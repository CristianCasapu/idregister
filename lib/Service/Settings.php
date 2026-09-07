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
    public const DEFAULTS = [
        'enabled' => false,
        'requireApproval' => false,
        'defaultGroup' => '',
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
            $raw = match (true) {
                \is_bool($default) => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
                \is_int($default) => (string) max(1, (int) $value),
                \is_float($default) => (string) min(1.0, max(0.0, (float) $value)),
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
