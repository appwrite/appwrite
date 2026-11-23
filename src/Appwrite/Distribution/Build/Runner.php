<?php

namespace Appwrite\Distribution\Build;

enum Runner: string
{
    case Orchestrator = 'orchestrator';
    case Pool = 'pool';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $runner): string => $runner->value, self::cases());
    }
}
