<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Sites extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'name',
        'enabled',
        'framework',
        'deploymentId',
        'buildCommand',
        'installCommand',
        'outputDirectory',
        'installationId'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('sites', self::ALLOWED_ATTRIBUTES);
    }
}
