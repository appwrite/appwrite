<?php

namespace Appwrite\Network\Validator;

use Utopia\Emails\Validator\Email as UtopiaEmailValidator;

/**
 * Email
 *
 * Validate that an variable is a valid email address
 * Extends the new Utopia Emails validator to maintain backward compatibility
 *
 * @package Appwrite\Network\Validator
 */
class Email extends UtopiaEmailValidator
{
    protected bool $allowEmpty;

    public function __construct(bool $allowEmpty = false)
    {
        $this->allowEmpty = $allowEmpty;
    }

    /**
     * Is valid
     *
     * Validation will pass when $value is valid email address.
     *
     * @param  mixed $value
     * @return bool
     */
    public function isValid($value): bool
    {
        if ($this->allowEmpty && \strlen($value) === 0) {
            return true;
        }

        return parent::isValid($value);
    }
}