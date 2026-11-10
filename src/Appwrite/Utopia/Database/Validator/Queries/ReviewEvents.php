<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class ReviewEvents extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'type',
        'time',
    ];

    public function __construct()
    {
        parent::__construct('reviewEvents', self::ALLOWED_ATTRIBUTES);
    }
}
