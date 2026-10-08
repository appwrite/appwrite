<?php

declare(strict_types=1);

namespace Utopia\Validator;

use Utopia\Validator;

/**
 * Numeric
 *
 * Validate that an variable is numeric
 */
class Numeric extends Validator
{
    /**
     * Get Description
     *
     * Returns validator description
     */
    public function getDescription(): string
    {
        return 'Value must be a valid number';
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
     * Returns validator type.
     */
    public function getType(): string
    {
        return self::TYPE_MIXED;
    }

    /**
     * Is valid
     *
     * Validation will pass when $value is numeric.
     */
    public function isValid(mixed $value): bool
    {
        return is_numeric($value);
    }
}
