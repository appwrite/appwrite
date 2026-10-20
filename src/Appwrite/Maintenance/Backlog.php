<?php

namespace Appwrite\Maintenance;

/**
 * Thrown to leave a slice uncommitted while the deletes queue drains, so the scheduler delivers it again.
 */
final class Backlog extends \RuntimeException
{
    public function __construct(public readonly int $pending)
    {
        parent::__construct('Deletes queue holds ' . $pending . ' pending messages');
    }
}
