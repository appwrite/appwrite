<?php

namespace Appwrite\Distribution\Signing;

enum Mode: string
{
    case Imported = 'imported';
    case Managed = 'managed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $mode): string => $mode->value, self::cases());
    }
}
