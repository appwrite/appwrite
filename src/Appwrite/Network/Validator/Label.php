<?php

namespace Appwrite\Network\Validator;

use Utopia\Validator\Validator;

/**
 * A single DNS label, such as the subdomain or region a provider hostname is built from:
 * 1 to 63 ASCII letters, digits or hyphens, not starting or ending with a hyphen.
 */
class Label extends Validator
{
    public const int MAX_LENGTH = 63;

    public function getDescription(): string
    {
        return 'Value must be 1 to ' . self::MAX_LENGTH . ' letters, digits or hyphens, and must not start or end with a hyphen.';
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return self::TYPE_STRING;
    }

    public function isValid(mixed $value): bool
    {
        if (!\is_string($value) || $value === '' || \strlen($value) > self::MAX_LENGTH) {
            return false;
        }

        if (\str_starts_with($value, '-') || \str_ends_with($value, '-')) {
            return false;
        }

        return \ctype_alnum(\str_replace('-', '', $value));
    }
}
