<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Sources extends Base
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
        parent::__construct('sources', self::ALLOWED_ATTRIBUTES);
    }
}
