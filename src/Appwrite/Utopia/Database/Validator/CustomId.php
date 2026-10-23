<?php

namespace Appwrite\Utopia\Database\Validator;

use Utopia\Database\Validator\Key;

class CustomId extends Key
{
    public const string UNIQUE = 'unique()';

    /**
     * Is valid.
     *
     * Returns true if valid or false if not.
     *
     * @param $value
     *
     * @return bool
     */
    public function isValid($value): bool
    {

        return $value == self::UNIQUE || parent::isValid($value);
    }
}
