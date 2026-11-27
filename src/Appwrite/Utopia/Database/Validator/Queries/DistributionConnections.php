<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class DistributionConnections extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'provider',
        'name',
        'status',
        'checkedAt',
        'search',
    ];

    public function __construct()
    {
        parent::__construct('distributionConnections', self::ALLOWED_ATTRIBUTES);
    }
}
