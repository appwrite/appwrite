<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Functions extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'name',
        'enabled',
        'runtime',
        'deployment',
        'schedule',
        'scheduleNext',
        'schedulePrevious',
        'timeout',
        'entrypoint',
        'commands',
        'installationId'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('functions', self::ALLOWED_ATTRIBUTES);
    }
}
