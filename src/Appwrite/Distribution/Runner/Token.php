<?php

namespace Appwrite\Distribution\Runner;

use Utopia\Database\Validator\UID;

/**
 * A runner's bearer token, `{runnerId}.{nonce}.{mac}`. It names its runner, so a
 * route verifies it and learns which runner it is for before reading anything.
 */
final readonly class Token
{
    public const string KEY_REQUIRED = 'Distribution runner tokens need _APP_OPENSSL_KEY_V1.';

    private const int NONCE_BYTES = 32;

    private const int HEX = 64;

    public function __construct(public string $runnerId)
    {
    }

    /**
     * @throws \LogicException When the secret is empty
     */
    public static function issue(string $runnerId, #[\SensitiveParameter] string $secret): string
    {
        if ($secret === '') {
            throw new \LogicException(self::KEY_REQUIRED);
        }

        $nonce = \bin2hex(\random_bytes(self::NONCE_BYTES));

        return "{$runnerId}.{$nonce}." . self::mac($runnerId, $nonce, $secret);
    }

    public static function verify(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $secret): ?self
    {
        if ($secret === '') {
            return null;
        }

        $parts = \explode('.', $token);
        if (\count($parts) !== 3) {
            return null;
        }

        [$runnerId, $nonce, $mac] = $parts;
        if (!self::isHex($nonce) || !self::isHex($mac) || !\hash_equals(self::mac($runnerId, $nonce, $secret), $mac)) {
            return null;
        }

        if (!(new UID())->isValid($runnerId)) {
            return null;
        }

        return new self($runnerId);
    }

    private static function mac(string $runnerId, string $nonce, #[\SensitiveParameter] string $secret): string
    {
        return \hash_hmac('sha256', "runner:{$runnerId}.{$nonce}", $secret);
    }

    private static function isHex(string $value): bool
    {
        return \strlen($value) === self::HEX && \ctype_xdigit($value);
    }
}
