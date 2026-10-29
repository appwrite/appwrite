<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Alerts extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'monitorId',
        'name',
        'status',
        'type',
        'resourceType',
        'resourceId',
        'metric',
        'resolvedAt',
        'search',
    ];

    public function __construct()
    {
        parent::__construct('alerts', self::ALLOWED_ATTRIBUTES);
    }
}
