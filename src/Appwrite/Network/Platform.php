<?php

namespace Appwrite\Network;

class Platform
{
    public const TYPE_UNKNOWN = 'unknown';
    public const TYPE_WEB = 'web';
    public const TYPE_APPLE = 'apple';
    public const TYPE_ANDROID = 'android';
    public const TYPE_WINDOWS = 'windows';
    public const TYPE_LINUX = 'linux';
    public const TYPE_SCHEME = 'scheme';

    public const SCHEME_HTTP = 'http';
    public const SCHEME_HTTPS = 'https';
    public const SCHEME_CHROME_EXTENSION = 'chrome-extension';
    public const SCHEME_FIREFOX_EXTENSION = 'moz-extension';
    public const SCHEME_SAFARI_EXTENSION = 'safari-web-extension';
    public const SCHEME_EDGE_EXTENSION = 'ms-browser-extension';
    public const SCHEME_IOS = 'appwrite-ios';
    public const SCHEME_MACOS = 'appwrite-macos';
    public const SCHEME_WATCHOS = 'appwrite-watchos';
    public const SCHEME_TVOS = 'appwrite-tvos';
    public const SCHEME_ANDROID = 'appwrite-android';
    public const SCHEME_WINDOWS = 'appwrite-windows';
    public const SCHEME_LINUX = 'appwrite-linux';
    public const SCHEME_TAURI = 'tauri';

    public const string LOOPBACK_HOSTNAME = 'localhost';

    /**
     * Other spellings of LOOPBACK_HOSTNAME. They address the same interface, so
     * they resolve to it when matching an allow list: a deployment that trusts
     * `localhost` trusts these too, and one that does not trusts neither. IPv6
     * is bracketed to match what parse_url() returns for a host.
     *
     * They are aliases rather than an unconditional allowance on purpose.
     * Session cookies are SameSite=None (see `cookieSamesite`) and CORS runs
     * with credentials, so any origin accepted here can read authenticated
     * responses. Matching is host-only, which means every port on the interface
     * counts -- trusting loopback outright would hand that to any local web UI,
     * including one with an XSS. Tying it to the configured allow list keeps the
     * decision with whoever set _APP_DOMAIN / _APP_CONSOLE_DOMAIN.
     *
     * Match exactly. A prefix or substring test would wrongly accept remote
     * hosts such as `127.0.0.1.example.com`, `xlocalhost` or `[::1].evil.com`.
     * Spellings outside this list (`127.0.0.2`, `0:0:0:0:0:0:0:1`, `localhost.`)
     * fail closed.
     *
     * @var array<string>
     */
    public const array LOOPBACK_ALIASES = ['127.0.0.1', '[::1]'];

    /**
     * @var array<string, string> Map scheme types to user-friendly platform names.
     */
    private static array $names = [
        self::SCHEME_HTTP => 'Web',
        self::SCHEME_HTTPS => 'Web',
        self::SCHEME_IOS => 'iOS',
        self::SCHEME_MACOS => 'macOS',
        self::SCHEME_WATCHOS => 'watchOS',
        self::SCHEME_TVOS => 'tvOS',
        self::SCHEME_ANDROID => 'Android',
        self::SCHEME_WINDOWS => 'Windows',
        self::SCHEME_LINUX => 'Linux',
        self::SCHEME_CHROME_EXTENSION => 'Web (Chrome Extension)',
        self::SCHEME_FIREFOX_EXTENSION => 'Web (Firefox Extension)',
        self::SCHEME_SAFARI_EXTENSION => 'Web (Safari Extension)',
        self::SCHEME_EDGE_EXTENSION => 'Web (Edge Extension)',
        self::SCHEME_TAURI => 'Web (Tauri)',
    ];

    /**
     * Map deprecated platform types to their new consolidated types.
     *
     * The 1.9.x refactor consolidated ~15 platform types into 5 new ones.
     * Existing platforms in the database may still have old type values.
     *
     * @param string $type
     * @return string The mapped type, or the original if not deprecated.
     */
    public static function mapDeprecatedType(string $type): string
    {
        $mapping = [
            'flutter-web' => self::TYPE_WEB,
            'unity' => self::TYPE_WEB,
            'flutter-ios' => self::TYPE_APPLE,
            'flutter-macos' => self::TYPE_APPLE,
            'apple-ios' => self::TYPE_APPLE,
            'apple-macos' => self::TYPE_APPLE,
            'apple-watchos' => self::TYPE_APPLE,
            'apple-tvos' => self::TYPE_APPLE,
            'react-native-ios' => self::TYPE_APPLE,
            'flutter-android' => self::TYPE_ANDROID,
            'react-native-android' => self::TYPE_ANDROID,
            'flutter-windows' => self::TYPE_WINDOWS,
            'flutter-linux' => self::TYPE_LINUX,
        ];

        return $mapping[$type] ?? $type;
    }

    /**
     * Get user-friendly platform name from a scheme.
     *
     * @param string|null $scheme
     * @return string Empty string if scheme is not found.
     */
    public static function getNameByScheme(?string $scheme): string
    {
        return self::$names[$scheme] ?? '';
    }

    public static function getHostnames(array $platforms): array
    {
        $hostnames = [];
        foreach ($platforms as $platform) {
            $type = strtolower($platform['type'] ?? self::TYPE_UNKNOWN);
            $hostname = strtolower($platform['hostname'] ?? '');
            $key = strtolower($platform['key'] ?? '');

            switch ($type) {
                case 'flutter-web':
                case 'unity':
                case self::TYPE_WEB:
                    if (!empty($hostname)) {
                        $hostnames[] = $hostname;
                    }
                    break;
                case 'flutter-android':
                case 'react-native-android':
                case self::TYPE_ANDROID:
                case 'flutter-windows':
                case self::TYPE_WINDOWS:
                case 'flutter-linux':
                case self::TYPE_LINUX:
                case 'flutter-ios':
                case 'flutter-macos':
                case 'apple-ios':
                case 'apple-macos':
                case 'apple-watchos':
                case 'apple-tvos':
                case 'react-native-ios':
                case self::TYPE_APPLE:
                    if (!empty($key)) {
                        $hostnames[] = $key;
                    }
                    break;
                default:
                    break;
            }
        }
        return array_unique($hostnames);
    }

    public static function getSchemes(array $platforms): array
    {
        $schemes = [];
        foreach ($platforms as $platform) {
            $type = strtolower($platform['type'] ?? self::TYPE_UNKNOWN);
            $scheme = strtolower($platform['key'] ?? '');

            switch ($type) {
                case self::TYPE_SCHEME:
                    if (!empty($scheme) && preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme)) {
                        $schemes[] = $scheme;
                    }
                    break;
                case 'flutter-web':
                case 'unity':
                case self::TYPE_WEB:
                    $schemes[] = self::SCHEME_HTTP;
                    $schemes[] = self::SCHEME_HTTPS;
                    break;
                case 'flutter-android':
                case 'react-native-android':
                case self::TYPE_ANDROID:
                    $schemes[] = self::SCHEME_ANDROID;
                    break;
                case 'flutter-ios':
                case 'flutter-macos':
                case 'apple-ios':
                case 'apple-macos':
                case 'apple-watchos':
                case 'apple-tvos':
                case 'react-native-ios':
                case self::TYPE_APPLE:
                    $schemes[] = self::SCHEME_WATCHOS;
                    $schemes[] = self::SCHEME_MACOS;
                    $schemes[] = self::SCHEME_TVOS;
                    $schemes[] = self::SCHEME_IOS;
                    break;
                case 'flutter-windows':
                case self::TYPE_WINDOWS:
                    $schemes[] = self::SCHEME_WINDOWS;
                    break;
                case 'flutter-linux':
                case self::TYPE_LINUX:
                    $schemes[] = self::SCHEME_LINUX;
                    break;
                default:
                    break;
            }
        }
        return array_unique($schemes);
    }
}
