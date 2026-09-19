<?php

namespace Appwrite\Utopia\Database\Validator;

use Utopia\Database\Validator\Key;

class CustomId extends Key
{
    /**
     * @var string
     */
    private $validation_error_message = '';

    /**
     * Get Description.
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription(): string
    {
        $message = $this->message;
        if (!empty($this->validation_error_message)) {
            $message = $this->validation_error_message;
            $this->validation_error_message = '';
        }
        return $message;
    }

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
        if (!\is_string($value)) {
            $this->validation_error_message = 'Parameter must be a string and not any other data type.';
        }
        return $value == 'unique()' || parent::isValid($value);
    }
}
