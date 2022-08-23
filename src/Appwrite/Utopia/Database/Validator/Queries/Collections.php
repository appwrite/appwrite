<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\Collection;

class Collections extends Collection
{
    public const ALLOWED_ATTRIBUTES = [
        '$id',
        '$createdAt',
        '$updatedAt',
        
        'name',
        'enabled',
        'documentSecurity'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('collections', self::ALLOWED_ATTRIBUTES);
    }
}
