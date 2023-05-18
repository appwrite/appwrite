<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Identities extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'userId',
        'provider',
        'status',
        'providerUid',
        'providerEmail',
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('identities', self::ALLOWED_ATTRIBUTES);
    }
}
