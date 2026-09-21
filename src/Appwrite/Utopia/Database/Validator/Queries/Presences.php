<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Presences extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'userInternalId',
        'userId',
        'expiresAt',
        'status',
        'source',
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('presenceLogs', self::ALLOWED_ATTRIBUTES);
    }
}
