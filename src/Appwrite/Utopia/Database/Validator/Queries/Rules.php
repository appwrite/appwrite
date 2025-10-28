<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Rules extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'domain',
        'type',
        'trigger',
        'deploymentResourceType',
        'deploymentResourceId',
        'deploymentId',
        'deploymentVcsProviderBranch'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('rules', self::ALLOWED_ATTRIBUTES);
    }
}
