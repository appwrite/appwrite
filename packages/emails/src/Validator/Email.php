<?php

namespace Utopia\Emails\Validator;

use Utopia\Emails\Email as EmailParser;
use Utopia\Validator;

/**
 * Email
 *
 * Validate that a value is a valid email address
 */
class Email extends Validator
{
    protected bool $allowEmpty;

    public function __construct(bool $allowEmpty = false)
    {
        $this->allowEmpty = $allowEmpty;
    }

    /**
     * Get Description
     *
     * Returns validator description
     */
    public function getDescription(): string
    {
        return 'Value must be a valid email address';
    }

    /**
     * Is valid
     *
     * Validation will pass when $value is a valid email address
     *
     * @param  mixed  $value
     */
    public function isValid($value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        if ($this->allowEmpty && \strlen($value) === 0) {
            return true;
        }

        try {
            $email = new EmailParser($value);

            return $email->isValid();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Is array
     *
     * Function will return true if object is array.
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     */
    public function getType(): string
    {
        return self::TYPE_STRING;
    }
}
