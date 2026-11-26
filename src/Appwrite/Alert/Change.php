<?php

namespace Appwrite\Alert;

/**
 * What happened to an alert.
 */
enum Change: string
{
    case Firing = 'firing';
    case Resolved = 'resolved';
    case Acknowledged = 'acknowledged';
    case Unacknowledged = 'unacknowledged';

    /**
     * The action of its project event, `monitors.[monitorId].alerts.[alertId].<action>`.
     */
    public function event(): string
    {
        return match ($this) {
            self::Firing => 'create',
            self::Resolved, self::Acknowledged, self::Unacknowledged => 'update',
        };
    }
}
