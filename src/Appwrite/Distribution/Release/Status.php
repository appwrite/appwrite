<?php

namespace Appwrite\Distribution\Release;

enum Status: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Published = 'published';
    case Attention = 'attention';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
