<?php

namespace Appwrite\Distribution\Signing;

enum Status: string
{
    case Active = 'active';
    case Expiring = 'expiring';
    case Expired = 'expired';
    case Revoked = 'revoked';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
