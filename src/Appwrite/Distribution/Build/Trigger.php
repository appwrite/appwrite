<?php

namespace Appwrite\Distribution\Build;

enum Trigger: string
{
    case Manual = 'manual';
    case Vcs = 'vcs';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $trigger): string => $trigger->value, self::cases());
    }
}
