<?php

namespace Appwrite\Utopia\Request\Validator;

use Utopia\Validator;

/**
 * Placeholder for binary file parameters, used only to surface the
 * parameter in generated SDKs and specs. Validation happens elsewhere.
 */
class File extends Validator
{
    public function getDescription(): string
    {
        return 'File is not valid';
    }

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
