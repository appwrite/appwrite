<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Targets extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'userId',
        'providerId',
        'identifier',
        'providerType',
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('targets', self::ALLOWED_ATTRIBUTES);
    }
}
