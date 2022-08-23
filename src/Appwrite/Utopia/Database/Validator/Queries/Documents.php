<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\Base;

class Documents extends Base
{
    public const ALLOWED_ATTRIBUTES = [];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('documents', self::ALLOWED_ATTRIBUTES);
    }
}
