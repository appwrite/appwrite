<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\Base;

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
        'phoneVerification'
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
