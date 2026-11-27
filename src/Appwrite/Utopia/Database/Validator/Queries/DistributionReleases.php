<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class DistributionReleases extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'version',
        'status',
        'search',
    ];

    public function __construct()
    {
        parent::__construct('distributionReleases', self::ALLOWED_ATTRIBUTES);
    }
}
