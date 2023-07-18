<?php

namespace Appwrite\Auth\Validator;

use Utopia\Http\Validator;

/**
 * Phone.
 *
 * Validates a number for the E.164 format.
 */
class Phone extends Validator
{
    /**
     * Get Description.
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return "Phone number must start with a '+' can have a maximum of fifteen digits.";
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
        return is_string($value) && !!\preg_match('/^\+[1-9]\d{1,14}$/', $value);
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
        return self::TYPE_STRING;
    }
}
