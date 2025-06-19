<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Tables extends Base
{
    public const array ALLOWED_COLUMNS = [
        'name',
        'enabled',
        'rowSecurity'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('collections', self::ALLOWED_COLUMNS);
    }
}
