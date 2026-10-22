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
        return 'Headers must contain at most ' . $this->maxKeys . ' keys and ' . $this->maxSize . ' bytes in total. Valid key chars are a-z, A-Z, 0-9, and hyphen. Keys can\'t start or end with a hyphen, or start with "x-appwrite" or "x-open-runtimes". Values can\'t be arrays or objects, or contain line breaks or other control characters.';
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

            // A value is forwarded to the runtime as one header line, so a
            // line break or other control byte in it would end that line and
            // start another: one a caller is not allowed to send, such as
            // x-appwrite-user-id. Only the horizontal tab is a legal header byte.
            if (!self::isHeaderValue((string) ($val ?? ''))) {
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

    /**
     * x-appwrite-* carries what Appwrite tells the function about the
     * request; x-open-runtimes-* is the executor's protocol with the runtime
     * (secret, timeout, logging, log id). Neither is the caller's to send.
     */
    private static function isReserved(string $key): bool
    {
        $key = \strtolower($key);

        return \str_starts_with($key, 'x-appwrite-') || \str_starts_with($key, 'x-open-runtimes-');
    }

    /**
     * RFC 9110 field-value bytes: visible ASCII, space, horizontal tab and
     * obs-text; never CR, LF, NUL or another control byte.
     */
    private static function isHeaderValue(string $value): bool
    {
        $length = \strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $byte = \ord($value[$i]);
            if (($byte < 0x20 && $byte !== 0x09) || $byte === 0x7F) {
                return false;
            }
        }

        return true;
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
