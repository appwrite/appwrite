<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Messages extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'scheduledAt',
        'deliveredAt',
        'deliveredTotal',
        'status',
        'description',
        'providerType',
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
