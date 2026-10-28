<?php

namespace Appwrite\Alert;

enum Channel: string
{
    case Email = 'email';
    case Console = 'console';
    case Webhook = 'webhook';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $channel): string => $channel->value, self::cases());
    }
}
