<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Memberships extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'userId',
        'teamId',
        'invited',
        'joined',
        'confirm',
    ];

    /**
     * Expression constructor
     */
    public function __construct()
    {
        parent::__construct('memberships', self::ALLOWED_ATTRIBUTES);
    }
}
