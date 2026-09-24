<?php

declare(strict_types=1);

namespace Utopia\Validator;

use Utopia\Validator;

/**
 * Float
 *
 * Validate that an variable is a float
 */
class FloatValidator extends Validator
{
    /**
     * Pass true to accept float strings as valid float values
     * This option is good for validating query string params.
     */
    public function __construct(protected bool $loose = false) {}

    /**
     * Get Description
     *
     * Returns validator description
     */
    public function getDescription(): string
    {
        return 'Value must be a valid float';
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
        return self::TYPE_FLOAT;
    }

    /**
     * Is valid
     *
     * Validation will pass when $value is float.
     */
    public function isValid(mixed $value): bool
    {
        if ($this->loose) {
            if (!is_numeric($value)) {
                return false;
            }
            $value += 0;
        }
        return \is_float($value) || \is_int($value);
    }
}
