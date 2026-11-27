<?php

namespace Appwrite\Distribution\Connection;

enum Status: string
{
    case Active = 'active';
    case Invalid = 'invalid';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
