<?php

namespace Appwrite\Database\Validator;

use Utopia\Validator;

class Key extends Validator
{
    /**
     * @var string
     */
    protected $message = 'Parameter must contain only letters with no spaces or special chars and be shorter than 32 chars';

    /**
     * Get Description.
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription()
    {
        return $this->message;
    }

    /**
     * Is valid.
     *
     * Returns true if valid or false if not.
     *
     * @param $value
     *
     * @return bool
     */
    public function isValid($value)
    {
        if(!is_string($value)) {
            return false;
        }
        
        if (preg_match('/[^A-Za-z0-9\-\_]/', $value)) {
            return false;
        }

        if (mb_strlen($value) > 32) {
            return false;
        }

        return true;
    }
}
