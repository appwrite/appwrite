<?php

namespace Storage\Validators;

use Utopia\Validator;

class Upload extends Validator
{
    public function getDescription()
    {
        return 'Not a valid upload file';
    }

    /**
     * Check if a file is a valid upload file
     *
     * @param string $path
     *
     * @return bool
     */
    public function isValid($path)
    {
        if (\is_uploaded_file($path)) {
            return true;
        }
        
        return false;
    }
}
