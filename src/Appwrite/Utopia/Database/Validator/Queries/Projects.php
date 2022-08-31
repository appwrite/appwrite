<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\Base;

class Projects extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'name'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('projects', self::ALLOWED_ATTRIBUTES);
    }
}
