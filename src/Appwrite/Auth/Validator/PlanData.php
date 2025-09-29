<?php

namespace Appwrite\Auth\Validator;

use Utopia\Validator;

class PlanData extends Validator
{
    /**
     * Get Description
     */
    public function getDescription(): string
    {
        return 'Value must be a valid plan data structure';
    }

    /**
     * Is valid
     *
     * @param mixed $value
     */
    public function isValid($value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        if (!isset($value['name']) || empty($value['name'])) {
            return false;
        }

        if (!isset($value['planId']) || empty($value['planId'])) {
            return false;
        }

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $value['planId'])) {
            return false;
        }

        if (isset($value['price']) && (!is_int($value['price']) || $value['price'] < 0)) {
            return false;
        }

        if (isset($value['currency']) && !preg_match('/^[a-z]{3}$/', $value['currency'])) {
            return false;
        }

        if (isset($value['interval']) && !in_array($value['interval'], ['month', 'year', 'week', 'day'])) {
            return false;
        }

        if (isset($value['maxUsers']) && (!is_int($value['maxUsers']) || $value['maxUsers'] < 0)) {
            return false;
        }

        if (isset($value['features']) && !is_array($value['features'])) {
            return false;
        }

        return true;
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
        return self::TYPE_OBJECT;
    }
}