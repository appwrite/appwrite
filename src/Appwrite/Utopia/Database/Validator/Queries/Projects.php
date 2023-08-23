<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Projects extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'name',
        'teamId',
    ];

    /**
     * Expression constructor
     */
    public function __construct()
    {
        parent::__construct('projects', self::ALLOWED_ATTRIBUTES);
    }
}
