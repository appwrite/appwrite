<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Reports extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'appId',
        'type',
        'targetType',
        'target',
        'analyzedAt',
    ];

    public function __construct()
    {
        parent::__construct('reports', self::ALLOWED_ATTRIBUTES);
    }
}
