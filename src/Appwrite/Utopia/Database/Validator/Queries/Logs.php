<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Logs extends Executions
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
        parent::__construct(self::ALLOWED_ATTRIBUTES);
    }
}
