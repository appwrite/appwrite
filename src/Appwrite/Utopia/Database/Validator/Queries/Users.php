<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Users extends Base
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
        'labels',
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
