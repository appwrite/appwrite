<?php

namespace Appwrite\Distribution;

enum Provider: string
{
    case Apple = 'apple';
    case Google = 'google';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $provider): string => $provider->value, self::cases());
    }
}
