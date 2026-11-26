<?php

namespace Appwrite\Alert;

/**
 * What happened to an alert. Firing opens it and fires its `create` event;
 * every other change fires `update`, with the alert's new status in the payload.
 */
enum Change: string
{
    case Firing = 'firing';
    case Resolved = 'resolved';
    case Acknowledged = 'acknowledged';
    case Unacknowledged = 'unacknowledged';
}
