<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Providers extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'name',
        'provider',
        'type',
        'enabled',
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('providers', self::ALLOWED_ATTRIBUTES);
    }
}
