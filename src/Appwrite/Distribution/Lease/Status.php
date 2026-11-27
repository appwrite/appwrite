<?php

namespace Appwrite\Distribution\Lease;

enum Status: string
{
    case Queued = 'queued';
    case Leased = 'leased';
    case Released = 'released';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
