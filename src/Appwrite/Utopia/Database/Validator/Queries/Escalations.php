<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Escalations extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'name',
        'repeat',
        'search',
    ];

    public function __construct()
    {
        parent::__construct('escalations', self::ALLOWED_ATTRIBUTES);
    }
}
