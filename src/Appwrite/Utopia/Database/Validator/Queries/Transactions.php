<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Transactions extends Base
{
    /** @var array<string> */
    public const array ALLOWED_ATTRIBUTES = [
        'status',
        'expiresAt',
    ];

    public function __construct()
    {
        parent::__construct('transactions', self::ALLOWED_ATTRIBUTES);
    }
}
