<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class DistributionLogs extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'stage',
        'sequence',
        'chunk',
    ];

    public function __construct()
    {
        parent::__construct('distributionLogs', self::ALLOWED_ATTRIBUTES);
    }
}
