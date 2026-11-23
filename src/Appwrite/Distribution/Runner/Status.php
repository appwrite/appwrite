<?php

namespace Appwrite\Distribution\Runner;

enum Status: string
{
    case Online = 'online';
    case Draining = 'draining';
    case Offline = 'offline';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
