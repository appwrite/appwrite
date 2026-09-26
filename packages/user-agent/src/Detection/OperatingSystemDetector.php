<?php

declare(strict_types=1);

namespace Utopia\UserAgent\Detection;

use Utopia\UserAgent\OperatingSystem;

final class OperatingSystemDetector
{
    /** @var array<string, string> */
    private const array WINDOWS_VERSIONS = [
        '10.0' => '10',
        '6.4' => '10',
        '6.3' => '8.1',
        '6.2' => '8',
        '6.1' => '7',
        '6.0' => 'Vista',
        '5.2' => 'XP',
        '5.1' => 'XP',
        '5.0' => '2000',
    ];

    /**
     * GNU/Linux distributions keyed by short code. Each entry is the display
     * name and a token that identifies the distribution. Ordered so that more
     * specific names win over generic ones.
     *
     * @var array<string, array{string, string}>
     */
    private const array LINUX_DISTROS = [
        'UBT' => ['Ubuntu', 'Ubuntu'],
        'KBT' => ['Kubuntu', 'Kubuntu'],
        'XBT' => ['Xubuntu', 'Xubuntu'],
        'LBT' => ['Lubuntu', 'Lubuntu'],
        'MIN' => ['Mint', 'Linux Mint'],
        'DEB' => ['Debian', 'Debian'],
        'KAL' => ['Kali', 'Kali'],
        'RAS' => ['Raspbian', 'Raspbian'],
        'FED' => ['Fedora', 'Fedora'],
        'RHT' => ['Red Hat', 'Red Hat'],
        'CES' => ['CentOS', 'CentOS'],
        'ROC' => ['Rocky Linux', 'Rocky'],
        'ARL' => ['Arch Linux', 'Arch'],
        'MJR' => ['Manjaro', 'Manjaro'],
        'GNT' => ['Gentoo', 'Gentoo'],
        'SLW' => ['Slackware', 'Slackware'],
        'SSE' => ['SUSE', 'SUSE'],
        'ORA' => ['Oracle Linux', 'Oracle'],
    ];

    public static function detect(string $userAgent): OperatingSystem
    {
        if ($userAgent === '') {
            return new OperatingSystem();
        }

        if (preg_match('/Windows Phone(?: OS)?[ \/]([0-9._]+)/i', $userAgent, $matches) === 1) {
            return new OperatingSystem('WPH', 'Windows Phone', self::version($matches[1]));
        }

        if (preg_match('/Windows NT[ \/]([0-9.]+)/i', $userAgent, $matches) === 1) {
            $version = self::WINDOWS_VERSIONS[$matches[1]] ?? $matches[1];

            return new OperatingSystem('WIN', 'Windows', $version);
        }

        if (stripos($userAgent, 'OpenHarmony') !== false) {
            return new OperatingSystem('OHS', 'OpenHarmony', self::token($userAgent, 'OpenHarmony'));
        }

        if (stripos($userAgent, 'HarmonyOS') !== false) {
            return new OperatingSystem('HAR', 'HarmonyOS', self::token($userAgent, 'HarmonyOS'));
        }

        $apple = self::apple($userAgent);
        if ($apple instanceof OperatingSystem) {
            return $apple;
        }

        // Amazon's Fire OS is Android based, so it must resolve before Android.
        if (self::isFireOs($userAgent)) {
            return new OperatingSystem('FIR', 'Fire OS');
        }

        if (preg_match('/Android(?: |\/)([0-9][0-9._-]*)/i', $userAgent, $matches) === 1) {
            return new OperatingSystem('AND', 'Android', self::version($matches[1]));
        }

        if (stripos($userAgent, 'Android') !== false) {
            return new OperatingSystem('AND', 'Android');
        }

        if (preg_match('/KaiOS[ \/]([0-9.]+)/i', $userAgent, $matches) === 1) {
            return new OperatingSystem('KOS', 'KaiOS', self::version($matches[1]));
        }

        if (preg_match('/Tizen[ \/]([0-9.]+)/i', $userAgent, $matches) === 1) {
            return new OperatingSystem('TIZ', 'Tizen', self::version($matches[1]));
        }

        if (preg_match('/CrOS [^ )]+ ([0-9.]+)/i', $userAgent, $matches) === 1) {
            return new OperatingSystem('COS', 'Chrome OS', self::version($matches[1]));
        }

        if (stripos($userAgent, 'web0S') !== false || stripos($userAgent, 'webOS') !== false) {
            $version = null;
            if (preg_match('/(?:web0S|webOS)[ \/]([0-9.]+)/i', $userAgent, $matches) === 1) {
                $version = self::version($matches[1]);
            }

            return new OperatingSystem('WOS', 'webOS', $version);
        }

        if (stripos($userAgent, 'Sailfish') !== false) {
            return new OperatingSystem('SAF', 'Sailfish OS');
        }

        if (preg_match('/(?:BlackBerry|BB10|RIM Tablet OS)/i', $userAgent) === 1) {
            return new OperatingSystem('BLB', 'BlackBerry OS');
        }

        if (preg_match('/Nintendo (?:Switch|Wii ?U?|3DS)/i', $userAgent) === 1) {
            return new OperatingSystem('WII', 'Nintendo');
        }

        if (stripos($userAgent, 'PlayStation') !== false) {
            return new OperatingSystem('PS3', 'PlayStation');
        }

        $distro = self::linuxDistro($userAgent);
        if ($distro instanceof OperatingSystem) {
            return $distro;
        }

        if (stripos($userAgent, 'Mac OS X') !== false && stripos($userAgent, 'like Mac OS X') === false) {
            if (preg_match('/Mac OS X[ \/]([0-9_\.]+)/i', $userAgent, $matches) === 1) {
                return new OperatingSystem('MAC', 'Mac', self::displayVersion($matches[1]));
            }

            return new OperatingSystem('MAC', 'Mac');
        }

        if (stripos($userAgent, 'Linux') !== false || stripos($userAgent, 'X11') !== false) {
            return new OperatingSystem('LIN', 'GNU/Linux');
        }

        return new OperatingSystem();
    }

    /**
     * Detect the Apple operating-system family: tvOS, watchOS, iPadOS, and iOS.
     */
    private static function apple(string $userAgent): ?OperatingSystem
    {
        if (stripos($userAgent, 'AppleTV') !== false || stripos($userAgent, 'tvOS') !== false) {
            return new OperatingSystem('ATV', 'tvOS', self::token($userAgent, 'tvOS'));
        }

        if (stripos($userAgent, 'Watch OS') !== false || stripos($userAgent, 'WatchOS') !== false) {
            $version = self::token($userAgent, 'WatchOS') ?? self::token($userAgent, 'Watch OS');

            return new OperatingSystem('WAS', 'watchOS', $version);
        }

        if (stripos($userAgent, 'iPad') !== false) {
            return new OperatingSystem('IPA', 'iPadOS', self::appleVersion($userAgent));
        }

        if (preg_match('/(?:iPhone|iPod)/i', $userAgent) === 1
            || preg_match('/(?:CPU (?:iPhone )?OS|iPhone OS)[ \/]([0-9_]+)/i', $userAgent) === 1) {
            return new OperatingSystem('IOS', 'iOS', self::appleVersion($userAgent));
        }

        return null;
    }

    private static function appleVersion(string $userAgent): ?string
    {
        if (preg_match('/(?:CPU (?:iPhone )?OS|iPhone OS|OS)[ \/]([0-9_]+)/i', $userAgent, $matches) === 1) {
            return self::version($matches[1]);
        }

        return null;
    }

    private static function isFireOs(string $userAgent): bool
    {
        if (stripos($userAgent, 'Android') === false) {
            return false;
        }

        return preg_match('/Silk\/|\bKF[A-Z0-9]{2,}\b|\bAFT[A-Z0-9]+\b/i', $userAgent) === 1;
    }

    private static function linuxDistro(string $userAgent): ?OperatingSystem
    {
        if (stripos($userAgent, 'Linux') === false && stripos($userAgent, 'X11') === false) {
            return null;
        }

        foreach (self::LINUX_DISTROS as $code => [$name, $token]) {
            if (preg_match('/\b' . preg_quote($token, '/') . '\b/i', $userAgent) !== 1) {
                continue;
            }

            return new OperatingSystem($code, $name, self::token($userAgent, $token));
        }

        return null;
    }

    /**
     * Read a numeric version that immediately follows a named token, whether
     * separated by a space or a slash (for example "Ubuntu/22.04" or
     * "OpenHarmony 5.0").
     */
    private static function token(string $userAgent, string $token): ?string
    {
        if (preg_match('/' . preg_quote($token, '/') . '[ \/]([0-9][0-9._]*)/i', $userAgent, $matches) === 1) {
            return self::version($matches[1]);
        }

        return null;
    }

    private static function version(string $version): string
    {
        return trim(str_replace('_', '.', $version), '.-');
    }

    private static function displayVersion(string $version): string
    {
        return implode('.', \array_slice(explode('.', self::version($version)), 0, 2));
    }
}
