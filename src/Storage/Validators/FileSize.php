<?php

namespace Storage\Validators;

use Utopia\Validator;

class FileSize extends Validator
{
    /**
     * @var int
     */
    protected $max;

    /**
     * @param int $max
     */
    public function __construct($max)
    {
        $this->max = $max;
    }

    public function getDescription()
    {
        return 'File size can\'t be bigger than ' . $this->max;
    }

    /**
     * Finds whether a file size is smaller than required limit
     *
     * @param  int  $fileSize
     * @return bool
     */
    public function isValid($fileSize)
    {
        if ($fileSize > $this->max) {
            return false;
        }

        return true;
    }
}
