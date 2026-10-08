<?php

declare(strict_types=1);

namespace Utopia\Validator;

use Utopia\Validator;

/**
 * Wildcard
 *
 * Does not perform any validation. Always returns valid
 */
class Wildcard extends Validator
{
    /**
     * Get Description
     *
     * Returns validator description
     */
    public function getDescription(): string
    {
        return 'Every input is valid';
    }

    /**
     * Is valid
     *
     * Validation will always pass irrespective of input
     *
     * @param  mixed  $value
     */
    public function isValid($value): bool
    {
        return true;
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
