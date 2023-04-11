<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Buckets extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'enabled',
        'name',
        'fileSecurity',
        'maximumFileSize',
        'encryption',
        'antivirus',
    ];

    /**
     * Expression constructor
     */
    public function __construct()
    {
        parent::__construct('buckets', self::ALLOWED_ATTRIBUTES);
    }
}
