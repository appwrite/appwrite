<?php

namespace Appwrite\Network\Validator;

use Utopia\Validator;

/**
 * Domain
 *
 * Validate that an variable is a valid domain address
 *
 * @package Utopia\Validator
 */
class Domain extends Validator
{
    /**
     * Get Description
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription()
    {
        return 'Value must be a valid domain';
    }

    /**
     * Is valid
     *
     * Validation will pass when $value is valid domain.
     *
     * Validates domain names against RFC 1034, RFC 1035, RFC 952, RFC 1123, RFC 2732, RFC 2181, and RFC 1123.
     *
     * @param  mixed $value
     * @return bool
     */
    public function isValid($value)
    {
        if (empty($value)) {
            return false;
        }

        if (!is_string($value)) {
            return false;
        }

        if (\filter_var($value, FILTER_VALIDATE_DOMAIN) === false) {
            return false;
        }

        return true;
    }
}
