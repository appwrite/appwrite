<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Passkeys extends Base
{
    public const ALLOWED_ATTRIBUTES = ['name', 'accessedAt'];

    public function __construct()
    {
        parent::__construct('authenticators', self::ALLOWED_ATTRIBUTES);
    }
}
