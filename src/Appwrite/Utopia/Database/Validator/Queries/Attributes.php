<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Attributes extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'key',
        'type',
        'size',
        'required',
        'array'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('attributes', self::ALLOWED_ATTRIBUTES);
    }
}
