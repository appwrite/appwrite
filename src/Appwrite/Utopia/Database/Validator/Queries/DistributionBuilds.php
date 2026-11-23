<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class DistributionBuilds extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'targetId',
        'platform',
        'type',
        'status',
        'stage',
        'attempt',
        'providerCommitHash',
        'providerBranch',
        'providerTag',
        'versionName',
        'versionCode',
        'buildNumber',
        'artifactId',
        'releaseId',
        'startedAt',
        'endedAt',
        'duration',
        'errorCode',
    ];

    public function __construct()
    {
        parent::__construct('distributionBuilds', self::ALLOWED_ATTRIBUTES);
    }
}
