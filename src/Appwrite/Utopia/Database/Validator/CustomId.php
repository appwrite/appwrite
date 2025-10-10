<?php
namespace Appwrite\Utopia\Database\Validator;

use Utopia\Database\Validator\Key;

class CustomId extends Key
{
    protected string $message = 'Invalid ID format';

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
        // Allow the special 'unique()' value for auto-generation
        if ($value === 'unique()') {
            return true;
        }

        // Empty values are invalid
        if (empty($value)) {
            $this->message = 'ID cannot be empty';
            return false;
        }

        // Validate length (max 36 characters)
        if (strlen($value) > 36) {
            $this->message = 'Invalid `rowId` param: UID must contain at most 36 chars. Valid chars are a-z, A-Z, 0-9, and underscore. Can\'t start with a leading underscore';
            return false;
        }

        // Validate format: must start with alphanumeric, can contain alphanumeric and underscore
        // Cannot start with underscore or special character
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_]*$/', $value)) {
            $this->message = 'Invalid `rowId` param: UID must contain at most 36 chars. Valid chars are a-z, A-Z, 0-9, and underscore. Can\'t start with a leading underscore';
            return false;
        }

        // All validations passed
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
        return self::TYPE_STRING;
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
        return 'Value must be a valid custom ID. Use unique() to generate a unique ID or provide a custom ID with max 36 chars (a-z, A-Z, 0-9, underscore). Cannot start with a leading underscore.';
    }
}
