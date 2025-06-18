<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Transactions extends Base
{
    public const array ALLOWED_ATTRIBUTES = [
        'status',
        'expiresAt',
    ];

    public function __construct()
    {
        parent::__construct('functions', self::ALLOWED_ATTRIBUTES);
    }
}
