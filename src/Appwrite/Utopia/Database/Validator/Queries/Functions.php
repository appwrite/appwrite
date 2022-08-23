<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\Collection;

class Functions extends Collection
{
    public const ALLOWED_ATTRIBUTES = [
        '$id',
        '$createdAt',
        '$updatedAt',
        
        'name',
        'status',
        'runtime',
        'deployment',
        'schedule',
        'scheduleNext',
        'schedulePrevious',
        'timeout'
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
