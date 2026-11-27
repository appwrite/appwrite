<?php

namespace Appwrite\Distribution\Build;

enum Stage: string
{
    case Source = 'source';
    case Build = 'build';
    case Sign = 'sign';
    case Store = 'store';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $stage): string => $stage->value, self::cases());
    }
}
