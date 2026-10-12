<?php

namespace Appwrite\Auth;

use Utopia\Auth\Proofs\Token;
use Utopia\Console\Console;

final class EncryptionKey
{
    public const string PLACEHOLDER = 'your-secret-key';

    public static function isInsecure(?string $key): bool
    {
        return $key === null || $key === '' || $key === self::PLACEHOLDER;
    }

    public static function resolve(string $key, bool $generate): string
    {
        if (!$generate || !self::isInsecure($key)) {
            return $key;
        }

        return (new Token())->generate();
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
