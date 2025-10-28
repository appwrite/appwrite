<?php

namespace Appwrite\Functions\Validator;

use Utopia\Validator;

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
        return 'Invalid headers: Alphanumeric characters or hyphens only, cannot start with "x-appwrite", maximum ' . $this->maxKeys . ' keys, and total size ' . $this->maxSize . '.';
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
            $length = \strlen($key);
            // Reject non-string keys
            if (!\is_string($key) || $length === 0) {
                return false;
            }

            $size += $length + \strlen($val);
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

            // Check for x-appwrite- prefix
            if (str_starts_with($key, 'x-appwrite-')) {
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
