<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class DistributionTargets extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'platform',
        'identifier',
        'connectionId',
        'signingIdentityId',
        'storeAppId',
        'track',
        'automatic',
        'versionStrategy',
        'versionCode',
    ];

    public function __construct()
    {
        parent::__construct('distributionTargets', self::ALLOWED_ATTRIBUTES);
    }
}
