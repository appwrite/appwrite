<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Messages extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'topics',
        'users',
        'targets',
        'providerId',
        'deliveredAt',
        'deliveredTo',
        'deliveryErrors',
        'status',
        'description',
        'data'
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
