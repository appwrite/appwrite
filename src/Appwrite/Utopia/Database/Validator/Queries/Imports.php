<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Imports extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'status',
        'stage',
        'source',
        'resources',
        'statusCounters',
        'resourceData',
        'errorData'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('imports', self::ALLOWED_ATTRIBUTES);
    }
}
