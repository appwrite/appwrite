<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Databases extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'name'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('databases', self::ALLOWED_ATTRIBUTES);
    }
}
