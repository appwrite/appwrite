<?php

namespace Appwrite\Distribution;

enum VersionStrategy: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $strategy): string => $strategy->value, self::cases());
    }
}
