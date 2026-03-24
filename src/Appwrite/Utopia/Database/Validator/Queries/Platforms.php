<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Platforms extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'type',
        'name',
        'hostname',
        'identifier',
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('platforms', self::ALLOWED_ATTRIBUTES);
    }
}
