<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Deployments extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'size',
        'status',
        'activate',
        'entrypoint',
        'commands',
        'type',
        'size',
        'buildSize',
        'buildTime'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('deployments', self::ALLOWED_ATTRIBUTES);
    }
}
