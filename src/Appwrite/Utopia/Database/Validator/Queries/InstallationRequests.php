<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class InstallationRequests extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'provider',
        'organization',
        'status',
    ];

    /**
     * Expression constructor
     */
    public function __construct()
    {
        parent::__construct('installationRequests', self::ALLOWED_ATTRIBUTES);
    }
}
