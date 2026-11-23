<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class DistributionSubmissions extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'releaseId',
        'targetId',
        'artifactId',
        'platform',
        'provider',
        'track',
        'rollout',
        'automatic',
        'status',
        'storeStatus',
        'step',
        'key',
        'submittedAt',
        'reviewedAt',
        'publishedAt',
        'errorCode',
    ];

    public function __construct()
    {
        parent::__construct('distributionSubmissions', self::ALLOWED_ATTRIBUTES);
    }
}
