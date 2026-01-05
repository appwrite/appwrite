<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Projects extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'name',
        'teamId',
        'labels',
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('projects', self::ALLOWED_ATTRIBUTES);
    }

    public function isSelectQueryAllowed(): bool
    {
        return true;
    }
}
