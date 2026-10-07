<?php

namespace Utopia\Database;

use Swoole\Database\DetectsLostConnections;

class Connection
{
    /**
     * @var array<string>
     */
    protected static array $errors = [
        'Max connect timeout reached'
    ];

    /**
     * Check if the given throwable was caused by a database connection error.
     *
     * @param \Throwable $e
     * @return bool
     */
    public static function hasError(\Throwable $e): bool
    {
        if (DetectsLostConnections::causedByLostConnection($e)) {
            return true;
        }

        $message = $e->getMessage();
        foreach (static::$errors as $needle) {
            if (\mb_strpos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
