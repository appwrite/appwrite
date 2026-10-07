<?php

namespace Utopia\Database\Validator;

use Utopia\Validator;

class ObjectValidator extends Validator
{
    /**
     * Get Description
     */
    public function getDescription(): string
    {
        return 'Value must be a valid object';
    }

    /**
     * Is Valid
     *
     * @param mixed $value
     */
    public function isValid(mixed $value): bool
    {
        if (is_string($value)) {
            // Check if it's valid JSON
            json_decode($value);
            return json_last_error() === JSON_ERROR_NONE;
        }

        if ($value instanceof \stdClass) {
            return true;
        }

        // Allow empty or associative arrays (non-list)
        return empty($value) || (is_array($value) && !array_is_list($value));
    }

    /**
     * Is Array
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     */
    public function getType(): string
    {
        return self::TYPE_OBJECT;
    }
}
