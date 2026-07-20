<?php

namespace Appwrite\Utopia\Request\Validator;

use Utopia\Validator;

/**
 * Placeholder validator for binary file parameters, so the parameter appears
 * in SDK specifications. Previously `Utopia\Storage\Validator\File`, removed
 * in storage 3.0. Validation is skipped for these parameters.
 */
class File extends Validator
{
    public function getDescription(): string
    {
        return 'File is not valid';
    }

    /**
     * @param mixed $name
     */
    public function isValid($name): bool
    {
        return true;
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return self::TYPE_STRING;
    }
}
