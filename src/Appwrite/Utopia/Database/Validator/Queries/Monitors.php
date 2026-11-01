<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Monitors extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'name',
        'type',
        'resourceType',
        'resourceId',
        'metric',
        'enabled',
        'status',
        'evaluatedAt',
        'triggeredAt',
        'search',
    ];

    public function __construct()
    {
        parent::__construct('monitors', self::ALLOWED_ATTRIBUTES);
    }
}
