<?php

namespace Utopia\Emails\Validator;

use Utopia\Emails\Email;
use Utopia\Validator;

/**
 * EmailCorporate
 *
 * Validate that an email address is from a corporate domain (not free or disposable)
 */
class EmailCorporate extends Validator
{
    /**
     * Get Description
     *
     * Returns validator description
     */
    public function getDescription(): string
    {
        return 'Value must be a valid email address from a corporate domain';
    }

    /**
     * Is valid
     *
     * Validation will pass when $value is a valid email address from a corporate domain
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

            return $email->isValid() && $email->isCorporate();
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
