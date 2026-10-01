<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Passkeys extends Base
{
    // Name, last use and backup state live in the encrypted data attribute, so only system attributes are queryable
    public const ALLOWED_ATTRIBUTES = [];

    public function __construct()
    {
        parent::__construct('authenticators', self::ALLOWED_ATTRIBUTES);
    }
}
