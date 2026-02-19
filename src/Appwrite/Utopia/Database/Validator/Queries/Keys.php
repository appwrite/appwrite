<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Keys extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'expire',
        'accessedAt',
        'name',
        'scopes',
    ];

    public function __construct()
    {
        parent::__construct('keys', self::ALLOWED_ATTRIBUTES);
    }
}
