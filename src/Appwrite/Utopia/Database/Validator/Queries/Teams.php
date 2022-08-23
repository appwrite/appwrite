<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\Collection;

class Teams extends Collection
{
    public const ALLOWED_ATTRIBUTES = [
        '$id',
        '$createdAt',
        '$updatedAt',

        'name',
        'total'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('teams', self::ALLOWED_ATTRIBUTES);
    }
}
