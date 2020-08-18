<?php

namespace Appwrite\Database\Validator;

use Utopia\Validator;

class Permissions extends Validator
{
    /**
     * @var string
     */
    protected $message = 'Permissions Error';

    /**
     * Get Description.
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription()
    {
        return $this->message;
    }

    /**
     * Is valid.
     *
     * Returns true if valid or false if not.
     *
     * @param array $value
     *
     * @return bool
     */
    public function isValid($value)
    {
        if (!\is_array($value) && !empty($value)) {
            $this->message = 'Invalid permissions data structure';

            return false;
        }

        foreach ($value as $action => $roles) {
            if (!\in_array($action, ['read', 'write'])) {
                $this->message = 'Unknown action ("'.$action.'")';

                return false;
            }

            if(!is_array($roles)) {
                $this->message = 'Permissions roles must be an array of strings';
                return false;
            }

            foreach ($roles as $role) {
                if (!\is_string($role)) {
                    $this->message = 'Permissions role must be a string';

                    return false;
                }
            }
        }

        return true;
    }
}
