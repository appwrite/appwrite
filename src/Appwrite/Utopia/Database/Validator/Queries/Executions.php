<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\Base;

class Executions extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'trigger',
        'status',
        'statusCode',
        'time'
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
