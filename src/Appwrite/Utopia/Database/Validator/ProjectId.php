<?php

namespace Appwrite\Utopia\Database\Validator;

use Utopia\Http\Validator;

class ProjectId extends Validator
{
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
        return $value == 'unique()' || preg_match('/^[a-z0-9][a-z0-9-]{1,35}$/', $value);
    }

    /**
     * Get description.
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Project IDs must contain at most 36 chars. Valid chars are a-z, 0-9, and hyphen. Can\'t start with a special char.';
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
