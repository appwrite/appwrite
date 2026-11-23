<?php

namespace Appwrite\Distribution\Build;

enum ReferenceType: string
{
    case Branch = 'branch';
    case Commit = 'commit';
    case Tag = 'tag';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
