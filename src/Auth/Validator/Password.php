<?php

namespace Auth\Validator;

use Utopia\Validator;

/**
 * Password
 *
 * Validates user password string
 *
 * @package Utopia\Validator
 */
class Password extends Validator
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
        return 'Password must be between 6 and 32 chars and contain ...';
    }

    /**
     * Is valid
     *
     * Validation username
     *
     * @param  mixed $value
     * @return bool
     */
    public function isValid($value)
    {
        if (strlen($value) < 6 || strlen($value) > 32) {
            return false;
        }

        return true;
    }
}