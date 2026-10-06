<?php

namespace Utopia\Emails\Validator;

use Utopia\Emails\Email;
use Utopia\Validator;

/**
 * EmailLocal
 *
 * Validate that an email address has a valid local part
 */
class EmailLocal extends Validator
{
    /**
     * Get Description
     *
     * Returns validator description
     */
    public function getDescription(): string
    {
        return 'Value must be a valid email address with a valid local part';
    }

    /**
     * Is valid
     *
     * Validation will pass when $value is a valid email address with a valid local part
     *
     * @param  mixed  $value
     */
    public function isValid($value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        try {
            $email = new Email($value);

            return $email->isValid() && $email->hasValidLocal();
        } catch (\Exception $e) {
            return false;
        }
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
        return self::TYPE_STRING;
    }
}
