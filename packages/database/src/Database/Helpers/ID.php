<?php

namespace Utopia\Database\Helpers;

use Utopia\Database\Exception as DatabaseException;

class ID
{
    /**
     * Create a new unique ID
     *
     * @throws DatabaseException
     */
    public static function unique(int $padding = 7): string
    {
        $uniqid = \uniqid();

        if ($padding > 0) {
            try {
                $bytes = \random_bytes(\max(1, (int)\ceil(($padding / 2)))); // one byte expands to two chars
            } catch (\Exception $e) {
                throw new DatabaseException($e->getMessage(), $e->getCode(), $e);
            }

            $uniqid .= \substr(\bin2hex($bytes), 0, $padding);
        }

        return $uniqid;
    }

    /**
     * Create a new ID from a string
     */
    public static function custom(string $id): string
    {
        return $id;
    }
}
