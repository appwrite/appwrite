<?php

namespace Utopia\Emails\Validator;

use Utopia\Emails\Email;
use Utopia\Validator;

/**
 * EmailNotDisposable
 *
 * Validate that an email address is not from a disposable email service
 */
class EmailNotDisposable extends Validator
{
    /**
     * Get Description
     *
     * Returns validator description
     */
    public function getDescription(): string
    {
        return 'Value must be a valid email address that is not from a disposable email service';
    }

    /**
     * Is valid
     *
     * Validation will pass when $value is a valid email address that is not disposable
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

            return $email->isValid() && ! $email->isDisposable();
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
