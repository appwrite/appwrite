<?php

declare(strict_types=1);

namespace Utopia\Storage\Validator;

use Utopia\Validator;

/**
 * @see \Utopia\Tests\Storage\Validator\FileNameTest
 */
class FileName extends Validator
{
    /**
     * Get Description
     */
    public function getDescription(): string
    {
        return 'Filename is not valid';
    }

    /**
     * The file name can only contain "a-z", "A-Z", "0-9", ".", "-", and "_", and not empty.
     *
     * @param  mixed  $name
     */
    public function isValid($name): bool
    {
        if (empty($name)) {
            return false;
        }

        if (! \is_string($name)) {
            return false;
        }
        return ctype_alnum(str_replace(['.', '-', '_'], '', $name));
    }

    /**
     * Is array
     *
     * Function will return true if object is array.
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     */
    public function getType(): string
    {
        return self::TYPE_STRING;
    }
}
