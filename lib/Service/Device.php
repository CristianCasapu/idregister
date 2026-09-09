<?php

declare(strict_types=1);

namespace OCA\IdRegister\Service;

/**
 * Is the visitor on a phone or a tablet? Registration needs a camera and a document in hand,
 * so a desktop is sent to a phone with a QR code instead.
 *
 * This reads the User-Agent, which can be faked; it is a guide for honest visitors, not a
 * security boundary. The browser also reports whether it has a touch screen.
 */
final class Device
{
    private const MOBILE = '/(Android|iPhone|iPod|iPad|Windows Phone|IEMobile|BlackBerry|BB10|Opera Mini|Mobile Safari|Silk|Kindle|Tablet|PlayBook)/i';
    private const DESKTOP_HINT = '/(Windows NT|Macintosh|X11; Linux|CrOS)/i';

    public static function isMobile(string $userAgent): bool
    {
        if ('' === trim($userAgent)) {
            return false;
        }
        if (1 === preg_match(self::MOBILE, $userAgent)) {
            return true;
        }

        // an iPad on recent iOS says "Macintosh"; the browser settles it with its touch report
        return 1 !== preg_match(self::DESKTOP_HINT, $userAgent);
    }

    /**
     * A short "Firefox on Windows" for the phone to show before a sign-in is approved. Read from
     * the same User-Agent, so it is a help for the person, not a proof of anything.
     */
    public static function describe(string $userAgent): string
    {
        $browsers = [
            'Edge' => '/Edg[e]?\//', 'Opera' => '/OPR\/|Opera/', 'Samsung Internet' => '/SamsungBrowser/',
            'Chrome' => '/Chrome|Chromium|CriOS/', 'Firefox' => '/Firefox|FxiOS/', 'Safari' => '/Safari/',
        ];
        $systems = [
            'Android' => '/Android/', 'iPhone' => '/iPhone/', 'iPad' => '/iPad/', 'Windows' => '/Windows NT/',
            'macOS' => '/Macintosh|Mac OS X/', 'Chrome OS' => '/CrOS/', 'Linux' => '/Linux/',
        ];
        $found = static function (array $candidates) use ($userAgent): string {
            foreach ($candidates as $name => $pattern) {
                if (1 === preg_match($pattern, $userAgent)) {
                    return $name;
                }
            }

            return '';
        };
        $browser = $found($browsers);
        $system = $found($systems);
        if ('' === $browser && '' === $system) {
            return 'unknown browser';
        }
        if ('' === $system) {
            return $browser;
        }

        return '' === $browser ? $system : $browser.' · '.$system;
    }
}
