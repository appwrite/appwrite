<?php

declare(strict_types=1);

namespace Utopia\Validator;

/**
 * Label
 *
 * Validates a single DNS label as RFC 1123 allows it in a hostname: 1 to 63
 * lowercase letters, digits and hyphens, not starting or ending with a hyphen.
 * Dots are rejected, so a value can be placed between fixed parts of a hostname
 * without adding or changing any other label.
 */
class Label extends Validator
{
    public const int MAX_LENGTH = 63;

    public function getDescription(): string
    {
        return 'Value must be 1 to ' . self::MAX_LENGTH . ' lowercase letters, digits and hyphens, and must not start or end with a hyphen.';
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

        return \ctype_alnum(\str_replace('-', '', $value)) && \strtolower($value) === $value;
    }
}
