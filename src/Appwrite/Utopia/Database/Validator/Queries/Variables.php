<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Variables extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'key',
        'resourceType',
        'resourceId'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('variables', self::ALLOWED_ATTRIBUTES);
    }
}
