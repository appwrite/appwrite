<?php

namespace Appwrite\Distribution\Runner;

enum Platform: string
{
    case Macos = 'macos';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $platform): string => $platform->value, self::cases());
    }
}
