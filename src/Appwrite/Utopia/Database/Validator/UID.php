<?php

namespace Appwrite\Utopia\Database\Validator;

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
