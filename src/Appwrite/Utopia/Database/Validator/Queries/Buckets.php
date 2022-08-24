<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\Base;

class Buckets extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'enabled',
        'name',
        'fileSecurity',
        'maximumFileSize',
        'encryption',
        'antivirus'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('buckets', self::ALLOWED_ATTRIBUTES);
    }
}