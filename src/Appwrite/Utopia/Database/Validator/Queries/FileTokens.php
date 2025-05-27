<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class FileTokens extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'expire',
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('files', self::ALLOWED_ATTRIBUTES);
    }
}
