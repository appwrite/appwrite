<?php

namespace Appwrite\Auth\Validator;

use Utopia\Validator;

/**
 * Username
 *
 * Validate that an variable is a valid username
 *
 */

 class Username extends Validator
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
        return 'Username must be 3-20 characters long and can include letters, numbers, underscores.';
    }

    /**
     * Is valid.
     *
     * Validation will pass when $value is a valid username.
     *
     * @return bool
     */

    public function isValid($username): bool
    {
        if (!is_string($username)) {
            return false;
        }

        return preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username);
    }



 }
