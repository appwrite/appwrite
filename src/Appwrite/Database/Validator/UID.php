<?php

namespace Appwrite\Database\Validator;

use Utopia\Validator;

class UID extends Validator
{
    /**
     * Get Description.
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription()
    {
        return 'Invalid UID format';
    }

    /**
     * Is valid.
     *
     * Returns true if valid or false if not.
     *
     * @param mixed $value
     *
     * @return bool
     */
    public function isValid($value)
    {
        if ($value === 0) { // TODO Deprecate confition when we get the chance.
            return true;
        }

        if (!is_string($value)) {
            return false;
        }
        
        if (mb_strlen($value) > 32) {
            return false;
        }

        return true;
    }
}
