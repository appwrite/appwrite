<?php

declare(strict_types=1);

namespace Utopia\Storage\Validator;

use Utopia\Validator;

/**
 * @see \Utopia\Tests\Storage\Validator\UploadTest
 */
class Upload extends Validator
{
    /**
     * Get Description
     */
    public function getDescription(): string
    {
        return 'Not a valid upload file';
    }

    /**
     * Check if a file is a valid upload file
     *
     * @param  mixed  $path
     */
    public function isValid($path): bool
    {
        if (! \is_string($path)) {
            return false;
        }
        return is_uploaded_file($path);
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
