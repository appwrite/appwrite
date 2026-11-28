<?php

namespace Appwrite\Distribution\Lease;

use Utopia\Database\Validator\UID;

/**
 * A lease's bearer token, `{leaseId}.{runnerId}.{projectInternalId}.{nonce}.{mac}`.
 * It names its lease, runner and project, so a route checks them against its path
 * and project before reading anything. The project's internal id is a sequence:
 * decimal digits on SQL adapters, a uuid7 on MongoDB.
 */
final readonly class Token
{
    public const string KEY_REQUIRED = 'Distribution runner tokens need _APP_OPENSSL_KEY_V1.';

    private const int NONCE_BYTES = 32;

    private const int HEX = 64;

    private const int LEASE_ID = 36;

    private const int UUID = 36;

    private const array UUID_DASHES = [8, 13, 18, 23];

    public function __construct(
        public string $leaseId,
        public string $runnerId,
        public string $projectInternalId,
    ) {
    }

    /**
     * @throws \LogicException When the secret is empty
     */
    public static function issue(string $leaseId, string $runnerId, string $projectInternalId, #[\SensitiveParameter] string $secret): string
    {
        if ($secret === '') {
            throw new \LogicException(self::KEY_REQUIRED);
        }

        $nonce = \bin2hex(\random_bytes(self::NONCE_BYTES));

        return "{$leaseId}.{$runnerId}.{$projectInternalId}.{$nonce}." . self::mac($leaseId, $runnerId, $projectInternalId, $nonce, $secret);
    }

    public static function verify(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $secret): ?self
    {
        if ($secret === '') {
            return null;
        }

        $parts = \explode('.', $token);
        if (\count($parts) !== 5) {
            return null;
        }

        [$leaseId, $runnerId, $projectInternalId, $nonce, $mac] = $parts;
        if (!self::isHex($nonce, self::HEX)
            || !self::isHex($mac, self::HEX)
            || !\hash_equals(self::mac($leaseId, $runnerId, $projectInternalId, $nonce, $secret), $mac)) {
            return null;
        }

        if (!self::isHex($leaseId, self::LEASE_ID) || !(new UID())->isValid($runnerId) || !self::isSequence($projectInternalId)) {
            return null;
        }

        return new self($leaseId, $runnerId, $projectInternalId);
    }

    private static function mac(string $leaseId, string $runnerId, string $projectInternalId, string $nonce, #[\SensitiveParameter] string $secret): string
    {
        return \hash_hmac('sha256', "lease:{$leaseId}.{$runnerId}.{$projectInternalId}.{$nonce}", $secret);
    }

    private static function isHex(string $value, int $length): bool
    {
        return \strlen($value) === $length && \ctype_xdigit($value);
    }

    private static function isSequence(string $value): bool
    {
        if ($value !== '' && \ctype_digit($value)) {
            return true;
        }

        if (\strlen($value) !== self::UUID) {
            return false;
        }

        foreach (self::UUID_DASHES as $position) {
            if ($value[$position] !== '-') {
                return false;
            }
        }

        $digits = \str_replace('-', '', $value);

        return \strlen($digits) === self::UUID - \count(self::UUID_DASHES) && \ctype_xdigit($digits);
    }
}
