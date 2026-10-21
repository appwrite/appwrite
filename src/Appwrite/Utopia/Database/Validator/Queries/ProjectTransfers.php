<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class ProjectTransfers extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'targetTeamId',
        'expire',
    ];

    public function __construct()
    {
        parent::__construct('transfers', self::ALLOWED_ATTRIBUTES);
    }
}
