<?php

declare(strict_types=1);

namespace Utopia\Validator;

use Utopia\Validator;

class JSON extends Validator
{
    public function getDescription(): string
    {
        return 'Value must be a valid JSON string';
    }

    /**
     * Is array
     *
     * Function will return true if object is array.
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     *
     * Returns validator type
     */
    public function getType(): string
    {
        return self::TYPE_OBJECT;
    }

    public function isValid(mixed $value): bool
    {
        if (\is_array($value)) {
            return true;
        }

        if (\is_string($value)) {
            json_decode($value);

            return json_last_error() === JSON_ERROR_NONE;
        }

        return false;
    }
}
