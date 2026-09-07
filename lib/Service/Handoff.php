<?php

declare(strict_types=1);

namespace OCA\IdRegister\Service;

use OCA\IdRegister\AppInfo\Application;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;

/**
 * Passing the registration from a desktop to a phone.
 *
 * The desktop asks for a handoff, shows its link as a QR code and then follows what the phone
 * is doing. Everything lives in the shared cache for half an hour; nothing touches the database.
 */
final class Handoff
{
    public const TTL = 1800;
    public const STATE_WAITING = 'waiting';
    public const STATE_OPENED = 'opened';
    public const STATE_DOCUMENT = 'document';
    public const STATE_REGISTERED = 'registered';
    public const STATE_CONFIRMED = 'confirmed';

    private ICache $cache;

    public function __construct(
        ICacheFactory $cacheFactory,
        private IURLGenerator $urlGenerator,
        private ISecureRandom $random,
    ) {
        $this->cache = $cacheFactory->createDistributed(Application::APP_ID.'_handoff');
    }

    /** @return array{token:string, url:string} */
    public function create(): array
    {
        $token = $this->random->generate(20, ISecureRandom::CHAR_ALPHANUMERIC);
        $this->write($token, ['state' => self::STATE_WAITING, 'name' => '', 'created' => time()]);

        return ['token' => $token, 'url' => $this->url($token)];
    }

    public function url(string $token): string
    {
        return $this->urlGenerator->linkToRouteAbsolute('idregister.page.index').'?s='.$token;
    }

    /** @return array{state:string, name:string, created:int}|null */
    public function get(string $token): ?array
    {
        if ('' === $token) {
            return null;
        }
        $value = $this->cache->get('h_'.$token);

        return \is_array($value) ? $value : null;
    }

    public function advance(string $token, string $state, string $name = ''): void
    {
        $current = $this->get($token);
        if (null === $current) {
            return;
        }
        $order = [self::STATE_WAITING, self::STATE_OPENED, self::STATE_DOCUMENT, self::STATE_REGISTERED, self::STATE_CONFIRMED];
        // never go backwards, so a reload on the phone does not undo progress
        if (array_search($state, $order, true) < array_search($current['state'], $order, true)) {
            return;
        }
        $current['state'] = $state;
        if ('' !== $name) {
            $current['name'] = $name;
        }
        $this->write($token, $current);
    }

    /** @param array{state:string, name:string, created:int} $value */
    private function write(string $token, array $value): void
    {
        $this->cache->set('h_'.$token, $value, self::TTL);
    }
}
