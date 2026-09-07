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
}
