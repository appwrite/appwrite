<?php

declare(strict_types=1);

namespace Utopia\Validator;

use Utopia\Validator;

/**
 * Bool
 *
 * Validate that an variable is a boolean value
 */
class Boolean extends Validator
{
    /**
     * Pass true to accept true and false strings and integers 0 and 1 as valid boolean values
     * This option is good for validating query string params.
     */
    public function __construct(protected bool $loose = false)
    {
    }

    /**
     * Get Description
     *
     * Returns validator description
     */
    public function getDescription(): string
    {
        return 'Value must be a valid boolean';
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
        return self::TYPE_BOOLEAN;
    }

    /**
     * Is valid
     *
     * Validation will pass when $value has a boolean value.
     *
     * @param  mixed  $value
     */
    public function isValid($value): bool
    {
        if ($this->loose && ($value === 'true' || $value === 'false')) { // Accept strings
            return true;
        }

        if ($this->loose && ($value === '1' || $value === '0')) { // Accept numeric strings
            return true;
        }

        if ($this->loose && ($value === 1 || $value === 0)) { // Accept integers
            return true;
        }
        return \is_bool($value);
    }

    /**
     * Convert loose query-string forms to a real boolean.
     *
     * String `"false"` is non-empty, so PHP's `(bool)` / typed `bool`
     * coercion turns it into `true`. Call this after {@see isValid()} so
     * action callbacks receive an actual `bool`.
     */
    public function parse(mixed $value): mixed
    {
        if (\is_bool($value)) {
            return $value;
        }

        if ($value === 'true' || $value === '1' || $value === 1) {
            return true;
        }

        if ($value === 'false' || $value === '0' || $value === 0) {
            return false;
        }

        return $value;
    }
}
