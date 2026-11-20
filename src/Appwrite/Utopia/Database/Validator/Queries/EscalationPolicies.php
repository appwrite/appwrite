<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class EscalationPolicies extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'name',
        'repeat',
        'search',
    ];

    public function __construct()
    {
        parent::__construct('escalationPolicies', self::ALLOWED_ATTRIBUTES);
    }
}
