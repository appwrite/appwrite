<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class DistributionApps extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'name',
        'enabled',
        'framework',
        'installationId',
        'providerRepositoryId',
        'repositoryId',
        'providerBranch',
        'providerSilentMode',
        'latestBuildId',
        'latestBuildStatus',
        'latestBuildCreatedAt',
        'search',
    ];

    public function __construct()
    {
        parent::__construct('distributionApps', self::ALLOWED_ATTRIBUTES);
    }
}
