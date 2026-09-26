<?php

declare(strict_types=1);

namespace Utopia\Storage\Validator;

use Utopia\Validator;

/**
 * @see \Utopia\Tests\Storage\Validator\FileExtTest
 */
class FileExt extends Validator
{
    public const TYPE_JPEG = 'jpeg';

    public const TYPE_JPG = 'jpg';

    public const TYPE_GIF = 'gif';

    public const TYPE_PNG = 'png';

    public const TYPE_GZIP = 'gz';

    public const TYPE_ZIP = 'zip';

    /**
     * @param  array<string>  $allowed
     */
    public function __construct(protected array $allowed) {}

    /**
     * Get Description
     */
    public function getDescription(): string
    {
        return 'File extension is not valid';
    }

    /**
     * Check if file extenstion is allowed
     *
     * @param  mixed  $filename
     */
    public function isValid($filename): bool
    {
        if (! \is_string($filename)) {
            return false;
        }
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return \in_array($ext, $this->allowed);
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
