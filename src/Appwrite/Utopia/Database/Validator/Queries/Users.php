<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\Collection;

class Users extends Collection
{
    public const ALLOWED_ATTRIBUTES = [
        'name',
        'email',
        'phone',
        'status',
        'passwordUpdate',
        'registration',
        'emailVerification',
        'phoneVerification',
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('users', self::ALLOWED_ATTRIBUTES);
    }
}
