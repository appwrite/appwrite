<?php

namespace Tests\Unit;

use Utopia\Validator\Text;

/**
 * Format Test for Email
 *
 * Validate that an variable is a valid email address
 *
 * @package Utopia\Validator
 */
class Format extends Text
{
    /**
     * Get Description
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Value must be a valid email address';
    }

    /**
     * Is valid
     *
     * Validation will pass when $value is valid email address.
     *
     * @param  mixed $value
     * @return bool
     */
    public function isValid($value): bool
    {
        if (!\filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return true;
    }
}
