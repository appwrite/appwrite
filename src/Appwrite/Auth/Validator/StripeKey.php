<?php

namespace Appwrite\Auth\Validator;

use Utopia\Validator;

class StripeKey extends Validator
{
    /**
     * Get Description
     */
    public function getDescription(): string
    {
        return 'Value must be a valid Stripe API key';
    }

    /**
     * Is valid
     *
     * @param mixed $value
     */
    public function isValid($value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        if (empty($value)) {
            return false;
        }

        if (preg_match('/^sk_(test|live)_[0-9a-zA-Z]{24,}$/', $value)) {
            return true;
        }

        if (preg_match('/^rk_(test|live)_[0-9a-zA-Z]{24,}$/', $value)) {
            return true;
        }

        return false;
    }

    /**
     * Is array
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     */
    public function getType(): string
    {
        return self::TYPE_STRING;
    }
}