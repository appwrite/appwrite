<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Destinations extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'type',
        'name',
        'data'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('destinations', self::ALLOWED_ATTRIBUTES);
    }
}
