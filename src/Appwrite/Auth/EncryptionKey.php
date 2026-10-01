<?php

namespace Appwrite\Auth;

/**
 * Server encryption / JWT signing key (`_APP_OPENSSL_KEY_V1`).
 *
 * The historical installer default `your-secret-key` is public. Production
 * processes must not start with that placeholder or an empty value.
 */
final class EncryptionKey
{
    public const string PLACEHOLDER = 'your-secret-key';

    public static function isInsecure(?string $key): bool
    {
        return $key === null || $key === '' || $key === self::PLACEHOLDER;
    }

    /**
     * @throws \RuntimeException
     */
    public static function assertProduction(string $env, ?string $key): void
    {
        if ($env !== 'production') {
            return;
        }

        if (self::isInsecure($key)) {
            throw new \RuntimeException('_APP_OPENSSL_KEY_V1 is missing or set to the insecure default. Set a unique secret before running in production.');
        }
    }
}
