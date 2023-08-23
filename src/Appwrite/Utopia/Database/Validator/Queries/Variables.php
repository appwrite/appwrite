<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\Base;

class Variables extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'key'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('variables', self::ALLOWED_ATTRIBUTES);
    }
}
