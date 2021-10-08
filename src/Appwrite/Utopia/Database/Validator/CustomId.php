<?php
namespace Utopia\Database\Validator;

use Utopia\Database\Validator\Key;

class CustomId extends Key {
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

        return $value == 'unique()' || parent::isValid($value);
    }
}