<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Messages extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'to',
        'providerId',
        'deliveredAt',
        'deliveredTo',
        'deliveryErrors',
        'status',
        'description'
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
