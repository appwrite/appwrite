<?php

namespace Appwrite\Reviews;

/**
 * The VCS providers code review runs on: an installation of one of these can have its
 * repositories reviewed. The review service (appwrite/hansi) has a client for each.
 */
enum Provider: string
{
    case GitHub = 'github';
    case Origin = 'origin';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $provider): string => $provider->value, self::cases());
    }
}
