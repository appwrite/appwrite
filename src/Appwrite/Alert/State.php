<?php

namespace Appwrite\Alert;

/**
 * Where an alert is in its life: open while its monitor fires, resolved once
 * the monitor recovers or is deleted.
 */
enum State: string
{
    case Firing = 'firing';
    case Resolved = 'resolved';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $state): string => $state->value, self::cases());
    }
}
