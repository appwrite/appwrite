<?php

namespace Database\Validator;

use Utopia\Validator;

class Role extends Validator
{
    /**
     * @var string
     */
    protected $message = 'Unknown Error';

    /**
     * Get Description
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
     * Is valid
     *
     * Returns true if valid or false if not.
     *
     * @param array $value
     * @return bool
     */
    public function isValid($value)
    {
        /*
        [
            '$collection' => self::SYSTEM_COLLECTION_RULES,
            'label' => 'Platforms',
            'key' => 'platforms',
            'type' => 'document',
            'default' => [],
            'required' => false,
            'array' => true,
            'options' => [
                '$collection' => self::SYSTEM_COLLECTION_OPTIONS,
                'whitelist' => [self::SYSTEM_COLLECTION_PLATFORMS]
            ],
        ],
        */

        if(!is_array($value) && !empty($value)) {
            $this->message = 'Invalid permissions data structure';
            return false;
        }

        foreach ($value as $action => $roles) {
            if(!in_array($action, ['read', 'write'])) {
                $this->message = 'Unknown action ("' .  $action. '")';
                return false;
            }

            foreach ($roles as $role) {
                if(!is_string($role)) {
                    $this->message = 'Permissions role must be a string';
                    return false;
                }
            }
        }

        return true;
    }
}