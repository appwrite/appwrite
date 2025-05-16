<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Logs extends Base
{
    public const ALLOWED_ATTRIBUTES = [
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
        parent::__construct('executions', self::ALLOWED_ATTRIBUTES); //TODO: Update this later
    }
}
