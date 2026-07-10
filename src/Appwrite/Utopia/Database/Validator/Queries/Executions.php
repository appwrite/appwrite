<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Utopia\Database\Database;

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

    public const ATTRIBUTE_TYPES = [
        'trigger' => Database::VAR_STRING,
        'status' => Database::VAR_STRING,
        'responseStatusCode' => Database::VAR_INTEGER,
        'duration' => Database::VAR_FLOAT,
        'requestMethod' => Database::VAR_STRING,
        'requestPath' => Database::VAR_STRING,
        'deploymentId' => Database::VAR_STRING,
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('executions', self::ALLOWED_ATTRIBUTES, self::ATTRIBUTE_TYPES);
    }
}
