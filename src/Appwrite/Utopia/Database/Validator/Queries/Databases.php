<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\Collection;

class Databases extends Collection
{
    public const ALLOWED_ATTRIBUTES = [
        '$id',
        '$createdAt',
        '$updatedAt',
        
        'name'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('databases', self::ALLOWED_ATTRIBUTES);
    }
}
