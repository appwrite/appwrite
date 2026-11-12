<?php

namespace Appwrite\Network\Validator;

use Utopia\Validator\Validator;

/**
 * SHA-256 fingerprint of an app signing certificate, as Play Console and `keytool` print it: 32 hex bytes
 * separated by colons, such as `14:6D:E9:83:...`.
 */
class Fingerprint extends Validator
{
    private const int BYTES = 32;

    public function getDescription(): string
    {
        return 'Value must be a SHA-256 certificate fingerprint of 32 colon-separated hex bytes, such as "14:6D:E9:...:2B:6A".';
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return self::TYPE_STRING;
    }

    public function isValid($value): bool
    {
        if (!\is_string($value)) {
            return false;
        }

        $bytes = \explode(':', $value);
        if (\count($bytes) !== self::BYTES) {
            return false;
        }

        foreach ($bytes as $byte) {
            if (\strlen($byte) !== 2 || !\ctype_xdigit($byte)) {
                return false;
            }
        }

        return true;
    }
}
