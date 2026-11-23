<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class DistributionSigningIdentities extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'platform',
        'name',
        'mode',
        'fingerprint',
        'teamId',
        'connectionId',
        'externalId',
        'expiresAt',
        'status',
    ];

    public function __construct()
    {
        parent::__construct('distributionSigningIdentities', self::ALLOWED_ATTRIBUTES);
    }
}
