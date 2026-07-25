<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Identities extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'userId',
        'provider',
        'providerUid',
        'providerEmail',
        'providerAccessTokenExpiry',
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
