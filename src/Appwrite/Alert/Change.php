<?php

namespace Appwrite\Alert;

/**
 * What happened to an alert, named as its project event:
 * `monitors.[monitorId].alerts.[alertId].<change>`.
 */
enum Change: string
{
    case Firing = 'firing';
    case Resolved = 'resolved';
    case Acknowledged = 'acknowledged';
    case Unacknowledged = 'unacknowledged';
}
