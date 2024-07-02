<?php

namespace Appwrite\Auth\Validator;

use Utopia\Http\Validator;

/**
 * Password.
 *
 * Validates user password string
 */
class Password extends Validator
{
    protected bool $allowEmpty;

    public function __construct(bool $allowEmpty = false)
    {
        $this->allowEmpty = $allowEmpty;
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
        return 'Password must be between 8 and 256 characters long.';
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
        if (!\is_string($value)) {
            return false;
        }

        if ($this->allowEmpty && \strlen($value) === 0) {
            return true;
        }

        if (\strlen($value) < 8) {
            return false;
        }

        if (\strlen($value) > 256) {
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
