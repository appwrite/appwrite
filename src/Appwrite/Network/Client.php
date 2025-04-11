<?php

namespace Appwrite\Network;

class Client
{
    /* Platform types, stored with DB */
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
    public const TYPE_CUSTOM_SCHEME = 'custom-scheme';

    /* Standard schemes used by Appwrite SDKs */
    public const SCHEME_TYPE_HTTP = 'http';
    public const SCHEME_TYPE_HTTPS = 'https';
    public const SCHEME_TYPE_IOS = 'appwrite-ios';
    public const SCHEME_TYPE_MACOS = 'appwrite-macos';
    public const SCHEME_TYPE_WATCHOS = 'appwrite-watchos';
    public const SCHEME_TYPE_TVOS = 'appwrite-tvos';
    public const SCHEME_TYPE_ANDROID = 'appwrite-android';
    public const SCHEME_TYPE_WINDOWS = 'appwrite-windows';
    public const SCHEME_TYPE_LINUX = 'appwrite-linux';

    /**
     * Get the name of the client plaform based on the scheme.
     *
     * @param string $scheme The scheme of the client.
     * @return string|null The name of the client platform.
     */
    public static function getName(string $scheme): ?string
    {
        return match ($scheme) {
            self::SCHEME_TYPE_HTTP => 'Web',
            self::SCHEME_TYPE_HTTPS => 'Web',
            self::SCHEME_TYPE_IOS => 'iOS',
            self::SCHEME_TYPE_MACOS => 'macOS',
            self::SCHEME_TYPE_WATCHOS => 'watchOS',
            self::SCHEME_TYPE_TVOS => 'tvOS',
            self::SCHEME_TYPE_ANDROID => 'Android',
            self::SCHEME_TYPE_WINDOWS => 'Windows',
            self::SCHEME_TYPE_LINUX => 'Linux',
            default => null,
        };
    }
}
