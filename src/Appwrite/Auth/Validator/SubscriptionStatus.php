<?php

namespace Appwrite\Auth\Validator;

use Utopia\Validator;

class SubscriptionStatus extends Validator
{
    private const VALID_STATUSES = [
        'none',
        'active',
        'canceled',
        'incomplete',
        'incomplete_expired',
        'past_due',
        'trialing',
        'unpaid',
        'paused'
    ];

    /**
     * Get Description
     */
    public function getDescription(): string
    {
        return 'Value must be a valid subscription status';
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

        return in_array($value, self::VALID_STATUSES);
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