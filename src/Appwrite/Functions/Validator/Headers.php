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
        return 'Headers must contain at most ' . $this->maxKeys . ' keys and ' . $this->maxSize . ' bytes in total. Valid key chars are a-z, A-Z, 0-9, and hyphen. Keys can\'t start or end with a hyphen, or start with "x-appwrite". Values can\'t be arrays or objects.';
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
