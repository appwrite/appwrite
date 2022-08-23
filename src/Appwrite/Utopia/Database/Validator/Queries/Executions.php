<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\Collection;

class Executions extends Collection
{
    public const ALLOWED_ATTRIBUTES = [
        '$id',
        '$createdAt',
        '$updatedAt',

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
