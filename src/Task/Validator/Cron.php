<?php

namespace Task\Validator;

use Cron\CronExpression;
use Utopia\Validator;

class Cron extends Validator
{
    /**
     * Get Description.
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription()
    {
        return 'String must be a valid cron expression';
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
    public function isValid($value)
    {
        if (!CronExpression::isValidExpression($value)) {
            return false;
        }

        return true;
    }
}
