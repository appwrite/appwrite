<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Columns extends Base
{
    public const array ALLOWED_COLUMNS = [
        'key',
        'type',
        'size',
        'required',
        'array',
        'status',
        'error'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('attributes', self::ALLOWED_COLUMNS);
    }
}
