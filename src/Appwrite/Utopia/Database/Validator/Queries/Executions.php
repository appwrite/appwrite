<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Utopia\Database\Database;

class Executions extends Base
{
    public const ATTRIBUTES = [
        ['$id' => 'trigger', 'type' => Database::VAR_STRING, 'array' => false],
        ['$id' => 'status', 'type' => Database::VAR_STRING, 'array' => false],
        ['$id' => 'responseStatusCode', 'type' => Database::VAR_INTEGER, 'array' => false],
        ['$id' => 'duration', 'type' => Database::VAR_FLOAT, 'array' => false],
        ['$id' => 'requestMethod', 'type' => Database::VAR_STRING, 'array' => false],
        ['$id' => 'requestPath', 'type' => Database::VAR_STRING, 'array' => false],
        ['$id' => 'deploymentId', 'type' => Database::VAR_STRING, 'array' => false],
    ];

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
        parent::__construct(['attributes' => self::ATTRIBUTES], self::ALLOWED_ATTRIBUTES);
    }
}
