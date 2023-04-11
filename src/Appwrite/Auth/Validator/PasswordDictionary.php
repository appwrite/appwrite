<?php

namespace Appwrite\Auth\Validator;

/**
 * Password.
 *
 * Validates user password string
 */
class PasswordDictionary extends Password
{
    protected array $dictionary;
    protected bool $enabled;

    public function __construct(array $dictionary, bool $enabled = false)
    {
        $this->dictionary = $dictionary;
        $this->enabled = $enabled;
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
        return 'Password must be at least 8 characters and should not be one of the commonly used password.';
    }

    /**
     * Is valid.
     *
     * @param mixed $value
     *
     * @return bool
     */
    public function isValid($value): bool
    {
        if (!parent::isValid($value)) {
            return false;
        }

        if ($this->enabled && array_key_exists($value, $this->dictionary)) {
            return false;
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
        return self::TYPE_STRING;
    }
}
