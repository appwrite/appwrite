<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Schedules extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'resourceType',
        'resourceId',
        'projectId',
        'schedule',
        'active',
        'region',
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('schedules', self::ALLOWED_ATTRIBUTES);
    }
}
