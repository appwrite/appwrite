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
        return 'Validate UUID format';
    }

    /**
     * Is valid.
     *
     * Returns true if valid or false if not.
     *
     * @param string $value
     *
     * @return bool
     */
    public function isValid($value)
    {
        if(mb_strlen($value) > 32) {
            return false;
        }

        return true;
    }
}
