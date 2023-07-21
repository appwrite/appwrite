<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Indexes extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'key',
        'type',
        'status',
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('attributes', self::ALLOWED_ATTRIBUTES);
    }
}
