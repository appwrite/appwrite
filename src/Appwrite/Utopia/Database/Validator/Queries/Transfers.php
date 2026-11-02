<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Transfers extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'recipientId',
        'expire',
    ];

    public function __construct()
    {
        parent::__construct('transfers', self::ALLOWED_ATTRIBUTES);
    }
}
