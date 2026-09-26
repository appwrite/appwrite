<?php

declare(strict_types=1);

namespace Utopia\Storage\Validator;

use Utopia\Validator;

/**
 * @see \Utopia\Tests\Storage\Validator\FileSizeTest
 */
class FileSize extends Validator
{
    /**
     * Max size in bytes
     *
     * @param  int  $max
     */
    public function __construct(protected $max) {}

    /**
     * Get Description
     */
    public function getDescription(): string
    {
        return 'File size can\'t be bigger than ' . $this->max;
    }

    /**
     * Finds whether a file size is smaller than required limit.
     *
     * @param  mixed  $fileSize
     */
    public function isValid($fileSize): bool
    {
        if (! \is_int($fileSize)) {
            return false;
        }
        return $fileSize <= $this->max;
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
        return self::TYPE_INTEGER;
    }
}
