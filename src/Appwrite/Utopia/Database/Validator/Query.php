<?php

namespace Appwrite\Utopia\Database\Validator;

use Utopia\Database\Validator\UID;
use Utopia\Database\Validator\Query as QueryValidator;

class Query extends QueryValidator
{
    protected function isValidCursor($cursor): bool
    {
        $validator = new UID();

        if ($validator->isValid($cursor)) {
            return true;
        }

        $this->message = 'Invalid cursor: ' . $validator->getDescription();
        return false;
    }
}
