<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\Collection;

class Deployments extends Collection
{
    public const ALLOWED_ATTRIBUTES = [
        '$id',
        '$createdAt',
        '$updatedAt',

        'entrypoint',
        'size',
        'buildId',
        'activate',
        'status',
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('deployments', self::ALLOWED_ATTRIBUTES);
    }
}
