<?php

namespace Appwrite\Auth;

use Utopia\Console;

/**
 * Server encryption / JWT signing key (`_APP_OPENSSL_KEY_V1`).
 *
 * The historical installer default `your-secret-key` is public. Encrypted
 * attributes are always written with this key and there is no rotation yet,
 * so installs still on the placeholder are warned rather than refused: a new
 * key would make their encrypted data unreadable.
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

        if ($key === null || $key === '') {
            throw new \RuntimeException('_APP_OPENSSL_KEY_V1 is missing. Set a unique secret before running in production.');
        }

        if ($key === self::PLACEHOLDER) {
            Console::warning('_APP_OPENSSL_KEY_V1 is the public default "' . self::PLACEHOLDER . '". Anyone can forge JWTs for this instance and decrypt its encrypted data.');
        }
    }
}
