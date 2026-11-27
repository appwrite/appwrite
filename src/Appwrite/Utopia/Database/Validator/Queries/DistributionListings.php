<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class DistributionListings extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'platform',
        'locale',
        'revision',
    ];

    public function __construct()
    {
        parent::__construct('distributionListings', self::ALLOWED_ATTRIBUTES);
    }
}
