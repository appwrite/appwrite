<?php

namespace Appwrite\Alert;

enum Status: string
{
    case Ok = 'ok';
    case Firing = 'firing';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
