<?php

namespace Appwrite\Distribution\Artifact;

enum Source: string
{
    case Build = 'build';
    case Upload = 'upload';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $source): string => $source->value, self::cases());
    }
}
