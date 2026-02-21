<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Subscribers extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'targetId',
        'topicId',
        'userId',
        'providerType'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('subscribers', self::ALLOWED_ATTRIBUTES);
    }
}
