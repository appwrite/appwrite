<?php

namespace Appwrite\Distribution\Artifact;

enum Type: string
{
    case Ipa = 'ipa';
    case Aab = 'aab';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
