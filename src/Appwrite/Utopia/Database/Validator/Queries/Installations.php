<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Installations extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'provider'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('installations', self::ALLOWED_ATTRIBUTES);
    }
}
