<?php

namespace Appwrite\Distribution\Artifact;

enum Status: string
{
    case Uploading = 'uploading';
    case Processing = 'processing';
    case Ready = 'ready';
    case Invalid = 'invalid';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
