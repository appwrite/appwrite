<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Teams extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'name',
        'total',
    ];

    /**
     * Expression constructor
     */
    public function __construct()
    {
        parent::__construct('teams', self::ALLOWED_ATTRIBUTES);
    }
}
