<?php

namespace Appwrite\Database\Validator;

use Appwrite\Database\Document;
use Utopia\Validator;

class Permissions extends Validator
{
    /**
     * @var string
     */
    protected $message = 'Permissions Error';

    /**
     * @var Document
     */
    protected $document;

    /**
     * Structure constructor.
     *
     * @param Document $document
     */
    public function __construct(Document $document)
    {
        $this->document = $document;
    }

    /**
     * Get Description.
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return $this->message;
    }

    /**
     * Is valid.
     *
     * Returns true if valid or false if not.
     *
     * @param mixed $value
     *
     * @return bool
     */
    public function isValid($value): bool
    {
        if (!\is_array($value) && !empty($value)) {
            $this->message = 'Invalid permissions data structure';

            return false;
        }

        foreach ($value as $action => $roles) {
            if (!\in_array($action, ['read', 'write', 'execute'])) {
                $this->message = 'Unknown action ("'.$action.'")';

                return false;
            }

            foreach ($roles as $role) {
                if (!\is_string($role)) {
                    $this->message = 'Permissions role must be of type string.';

                    return false;
                }
            }
        }

        return true;
    }
    /**
     * Is array
     *
     * Function will return true if object is array.
     *
     * @return bool
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     *
     * @return string
     */
    public function getType(): string
    {
        return self::TYPE_ARRAY;
    }
}
