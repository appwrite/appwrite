<?php

namespace Appwrite\Auth;

use Ahc\Jwt\JWT;
use Ahc\Jwt\JWTException;
use Utopia\Auth\Proofs\Token;
use Utopia\Console\Console;
use Utopia\System\System;

/**
 * The server secret, `_APP_OPENSSL_KEY_V{version}`. Rotating it adds a version:
 * the current one (`_APP_OPENSSL_KEY_CURRENT`) signs tokens and encrypts data,
 * every configured version from `_APP_OPENSSL_KEY_OLDEST` up still verifies
 * tokens, and stored data is decrypted by the version it records.
 */
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
     * The version new tokens are signed and new data is encrypted with.
     */
    public static function version(): string
    {
        return System::getEnv('_APP_OPENSSL_KEY_CURRENT', '1');
    }

    public static function get(string $version): string
    {
        return System::getEnv('_APP_OPENSSL_KEY_V' . $version, '');
    }

    public static function current(): string
    {
        return self::get(self::version());
    }

    /**
     * Keys a token or signature is verified against, current first. Versions
     * are walked up from the oldest accepted one until one is not configured,
     * so the next key can be deployed before it becomes current.
     *
     * @return list<string>
     */
    public static function accepted(): array
    {
        $keys = [self::current()];

        for ($version = (int) System::getEnv('_APP_OPENSSL_KEY_OLDEST', '1'); ($key = self::get((string) $version)) !== ''; $version++) {
            $keys[] = $key;
        }

        return \array_values(\array_unique(\array_filter($keys, fn (string $key) => $key !== '')));
    }

    /**
     * Decode a JWT signed with any accepted key.
     *
     * @return array<string, mixed>
     * @throws JWTException
     */
    public static function decode(string $token, string $algo = 'HS256', int $maxAge = 3600, int $leeway = 0): array
    {
        $error = new JWTException('Signing key cannot be empty', JWT::ERROR_KEY_EMPTY);

        foreach (self::accepted() as $key) {
            try {
                return (new JWT($key, $algo, $maxAge, $leeway))->decode($token);
            } catch (JWTException $error) {
                // Past the signature the key is right, so another one will not help
                if ($error->getCode() !== JWT::ERROR_SIGNATURE_FAILED) {
                    throw $error;
                }
            }
        }

        throw $error;
    }

    /**
     * @throws \RuntimeException
     */
    public static function assertProduction(string $env, ?string $key): void
    {
        if ($env !== 'production') {
            return;
        }

        $name = '_APP_OPENSSL_KEY_V' . self::version();

        if ($key === null || $key === '') {
            throw new \RuntimeException($name . ' is missing. Set a unique secret before running in production.');
        }

        if ($key === self::PLACEHOLDER) {
            Console::warning($name . ' is the public default "' . self::PLACEHOLDER . '". Anyone can forge JWTs for this instance and decrypt its encrypted data.');
        }
    }
}
