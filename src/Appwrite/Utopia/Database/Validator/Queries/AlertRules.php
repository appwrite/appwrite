<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class AlertRules extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'name',
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
        parent::__construct('alertRules', self::ALLOWED_ATTRIBUTES);
    }
}
