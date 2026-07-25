<?php

namespace Appwrite\Utopia\Database\Validator;

use Utopia\Database\Database;
use Utopia\Validator;

class ProjectId extends Validator
{
    /**
     * Constructor
     *
     * @param int $maxLength Maximum length for the project ID
     */
    public function __construct(protected readonly int $maxLength = Database::MAX_UID_DEFAULT_LENGTH)
    {
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
        if ($value == 'unique()') {
            return true;
        }

        // Must start with a-z or 0-9, followed by a-z, 0-9, or hyphen
        if (!\preg_match('/^[a-z0-9][a-z0-9-]*$/', $value)) {
            return false;
        }

        // Check length
        if (\mb_strlen($value) > $this->maxLength) {
            return false;
        }

        return true;
    }

    /**
     * Get description.
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Project IDs must contain at most ' . $this->maxLength . ' chars. Valid chars are a-z, 0-9, and hyphen. Can\'t start with a special char.';
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
}
