<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class DevKeys extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'accessedAt',
        'expire',
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('devKeys', self::ALLOWED_ATTRIBUTES);
    }
}
