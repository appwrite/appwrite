<?php

namespace Appwrite\Functions;

class Status
{
    public const QUEUED = 'queued';
    public const CANCELLED = 'cancelled';
    public const FAILED = 'failed';

    // For builds only
    public const READY = 'ready';
    public const BUILDING = 'building';

    // For executions only
    public const EXECUTING = 'executing';
    public const SCHEDULED = 'scheduled';
    public const SUCCESSFUL = 'successful';


}
