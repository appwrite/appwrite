<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\Collection;

class Buckets extends Collection
{
    public const ALLOWED_ATTRIBUTES = [
        '$id',
        '$createdAt',
        '$updatedAt',
        
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
