<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Tables extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'name',
        'enabled',
        'documentSecurity'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('collections', self::ALLOWED_ATTRIBUTES);
    }
}
