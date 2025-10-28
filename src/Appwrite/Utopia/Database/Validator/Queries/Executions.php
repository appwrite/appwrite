<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Executions extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'trigger',
        'status',
        'responseStatusCode',
        'duration',
        'requestMethod',
        'requestPath',
        'deploymentId'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('executions', self::ALLOWED_ATTRIBUTES);
    }
}
