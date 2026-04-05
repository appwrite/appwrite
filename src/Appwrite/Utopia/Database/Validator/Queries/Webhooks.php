<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Webhooks extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'name',
        'url',
        'httpUser',
        'security',
        'events',
        'enabled',
        'logs',
        'attempts',
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('webhooks', self::ALLOWED_ATTRIBUTES);
    }
}
