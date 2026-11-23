<?php

namespace Appwrite\Distribution;

enum Platform: string
{
    case Ios = 'ios';
    case Android = 'android';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $platform): string => $platform->value, self::cases());
    }
}
