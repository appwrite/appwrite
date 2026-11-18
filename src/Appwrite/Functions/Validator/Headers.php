<?php

namespace Appwrite\Functions\Validator;

use Utopia\Validator\Validator;

/**
 * Headers.
 *
 * Validates user provided headers
 */
class Headers extends Validator
{
    /**
     * Control characters a header value can't contain; tab is allowed
     */
    private const string CONTROL_CHARACTERS = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    public function __construct(protected bool $allowEmpty = true, protected int $maxKeys = 100, protected int $maxSize = 16384)
    {
    }

    /**
     * Get Description.
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Headers must contain at most ' . $this->maxKeys . ' keys and ' . $this->maxSize . ' bytes in total. Valid key chars are a-z, A-Z, 0-9, and hyphen. Keys can\'t start or end with a hyphen, or start with "x-appwrite". Values can\'t be arrays or objects, or contain control characters other than tab.';
    }

    /**
     * Is valid.
     *
     * @param mixed $value
     *
     * @return bool
     */
    public function isValid($value): bool
    {
        if ($this->allowEmpty && empty($value)) {
            return true;
        }

        if (!\is_array($value)) {
            return false;
        }

        if (\count($value) > $this->maxKeys) {
            return false;
        }

        $size = 0;
        foreach ($value as $key => $val) {
            // Reject non-string keys
            if (!\is_string($key)) {
                return false;
            }

            // Reject array and object values
            if (!\is_scalar($val) && !\is_null($val)) {
                return false;
            }

            // A line break in a value would let the caller append headers reserved for the platform
            if (\strpbrk((string) $val, self::CONTROL_CHARACTERS) !== false) {
                return false;
            }

            $length = \strlen($key);
            if ($length === 0) {
                return false;
            }

            $size += $length + \strlen($val ?? '');
            if ($size >= $this->maxSize) {
                return false;
            }

            // Check first and last character
            if (!ctype_alnum($key[0]) || !ctype_alnum($key[$length - 1])) {
                return false;
            }

            // Check middle characters
            for ($i = 1; $i < $length - 1; $i++) {
                if (!ctype_alnum($key[$i]) && $key[$i] !== '-') {
                    return false;
                }
            }

            if (self::isReserved($key)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Drop reserved Appwrite headers, matching names case-insensitively.
     * Names in $keep stay only when the header key matches exactly.
     *
     * @param array<array-key, mixed> $headers
     * @param list<string> $keep
     * @return array<array-key, mixed>
     */
    public static function stripReserved(array $headers, array $keep = []): array
    {
        $keepExact = [];
        foreach ($keep as $name) {
            $keepExact[$name] = true;
        }

        $sanitized = [];

        foreach ($headers as $key => $value) {
            if (\is_string($key) && self::isReserved($key) && !isset($keepExact[$key])) {
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    private static function isReserved(string $key): bool
    {
        return \str_starts_with(\strtolower($key), 'x-appwrite-');
    }

    /**
     * Is array
     *
     * Function will return true if object is array.
     *
     * @return bool
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     *
     * @return string
     */
    public function getType(): string
    {
        return self::TYPE_OBJECT;
    }
}
