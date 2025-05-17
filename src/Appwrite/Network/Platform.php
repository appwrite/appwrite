<?php

namespace Appwrite\Network;

class Platform
{
    public const TYPE_UNKNOWN = 'unknown';
    public const TYPE_WEB = 'web';
    public const TYPE_FLUTTER_IOS = 'flutter-ios';
    public const TYPE_FLUTTER_ANDROID = 'flutter-android';
    public const TYPE_FLUTTER_MACOS = 'flutter-macos';
    public const TYPE_FLUTTER_WINDOWS = 'flutter-windows';
    public const TYPE_FLUTTER_LINUX = 'flutter-linux';
    public const TYPE_FLUTTER_WEB = 'flutter-web';
    public const TYPE_APPLE_IOS = 'apple-ios';
    public const TYPE_APPLE_MACOS = 'apple-macos';
    public const TYPE_APPLE_WATCHOS = 'apple-watchos';
    public const TYPE_APPLE_TVOS = 'apple-tvos';
    public const TYPE_ANDROID = 'android';
    public const TYPE_UNITY = 'unity';
    public const TYPE_REACT_NATIVE_IOS = 'react-native-ios';
    public const TYPE_REACT_NATIVE_ANDROID = 'react-native-android';
    public const TYPE_SCHEME = 'scheme';

    public const SCHEME_HTTP = 'http';
    public const SCHEME_HTTPS = 'https';
    public const SCHEME_IOS = 'appwrite-ios';
    public const SCHEME_MACOS = 'appwrite-macos';
    public const SCHEME_WATCHOS = 'appwrite-watchos';
    public const SCHEME_TVOS = 'appwrite-tvos';
    public const SCHEME_ANDROID = 'appwrite-android';
    public const SCHEME_WINDOWS = 'appwrite-windows';
    public const SCHEME_LINUX = 'appwrite-linux';

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
    ];

    /**
     * Get user-friendly platform name from a scheme.
     *
     * @param string $scheme
     * @return string Empty string if scheme is not found.
     */
    public static function getNameByScheme(string $scheme): string
    {
        return self::$names[$scheme] ?? '';
    }

    public static function getHostnames(array $platforms): array
    {
        $hostnames = [];
        foreach ($platforms as $platform) {
            $type = $platform['type'] ?? self::TYPE_UNKNOWN;
            $hostname = strtolower($platform['hostname'] ?? '');
            $key = strtolower($platform['key'] ?? '');

            switch ($type) {
                case self::TYPE_WEB:
                case self::TYPE_FLUTTER_WEB:
                    $hostnames[] = $hostname;
                    break;
                case self::TYPE_FLUTTER_IOS:
                case self::TYPE_FLUTTER_ANDROID:
                case self::TYPE_FLUTTER_MACOS:
                case self::TYPE_FLUTTER_WINDOWS:
                case self::TYPE_FLUTTER_LINUX:
                case self::TYPE_ANDROID:
                case self::TYPE_APPLE_IOS:
                case self::TYPE_APPLE_MACOS:
                case self::TYPE_APPLE_WATCHOS:
                case self::TYPE_APPLE_TVOS:
                case self::TYPE_REACT_NATIVE_IOS:
                case self::TYPE_REACT_NATIVE_ANDROID:
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
            $type = $platform['type'] ?? self::TYPE_UNKNOWN;
            $scheme = $platform['key'] ?? '';

            switch ($type) {
                case self::TYPE_SCHEME:
                    $schemes[] = $scheme;
                    break;
                default:
                    break;
            }
        }
        return array_unique($schemes);
    }
}
