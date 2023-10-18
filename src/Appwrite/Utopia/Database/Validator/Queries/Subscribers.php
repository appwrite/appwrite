<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Subscribers extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'targetId',
        'topicId'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('messages', self::ALLOWED_ATTRIBUTES);
    }
}
