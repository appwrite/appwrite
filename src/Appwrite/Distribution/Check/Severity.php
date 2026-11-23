<?php

namespace Appwrite\Distribution\Check;

enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $severity): string => $severity->value, self::cases());
    }
}
