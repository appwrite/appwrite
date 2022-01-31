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
class Path extends Validator
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
        return 'Value must be a relative path without \'../\'';
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
    public function isValid($value): bool
    {
        if (empty($value)) {
            return false;
        }

        if (!is_string($value)) {
            return false;
        }

        if(\str_contains($value, '..')) {
            return false;
        }

        return true;
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
