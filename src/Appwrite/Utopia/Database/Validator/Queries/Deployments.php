<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Deployments extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'buildSize',
        'sourceSize',
        'totalSize',
        'buildDuration',
        'status',
        'activate',
        'type',
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('deployments', self::ALLOWED_ATTRIBUTES);
    }

    public function isSelectQueryAllowed(): bool
    {
        return true;
    }
}
