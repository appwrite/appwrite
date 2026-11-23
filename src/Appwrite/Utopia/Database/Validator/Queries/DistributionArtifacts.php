<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class DistributionArtifacts extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'targetId',
        'platform',
        'type',
        'source',
        'buildId',
        'status',
        'name',
        'size',
        'digest',
        'identifier',
        'versionName',
        'versionCode',
        'buildNumber',
        'signed',
        'fingerprint',
        'expiresAt',
    ];

    public function __construct()
    {
        parent::__construct('distributionArtifacts', self::ALLOWED_ATTRIBUTES);
    }
}
