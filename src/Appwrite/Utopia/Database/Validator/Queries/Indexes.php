<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Indexes extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'key',
        'type',
        'status',
        'attributes',
        'error',
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('indexes', self::ALLOWED_ATTRIBUTES);
    }
}
