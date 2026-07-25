<?php

namespace Appwrite\Task\Validator;

use Cron\CronExpression;
use Utopia\Validator;

class Cron extends Validator
{
    /**
     * Get Description.
     *
     * Returns validator description.
     *
     * @return string
     */
    public function getDescription(): string
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
    public function isValid($value): bool
    {
        if (empty($value)) {
            return true;
        }

        if (!CronExpression::isValidExpression($value)) {
            return false;
        }

        try {
            \set_error_handler(static function (int $severity, string $message): bool {
                if (($severity & E_WARNING) === E_WARNING) {
                    throw new \RuntimeException($message);
                }

                return false;
            });
            (new CronExpression($value))->getNextRunDate();
            return true;
        } catch (\RuntimeException) {
            return false;
        } finally {
            \restore_error_handler();
        }
    }

    /**
     * Is array.
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
     * Get Type.
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
