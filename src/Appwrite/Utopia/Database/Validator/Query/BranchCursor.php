<?php

namespace Appwrite\Utopia\Database\Validator\Query;

use Utopia\Database\Query;
use Utopia\Database\Validator\Query\Base;
use Utopia\Query\Method;
use Utopia\Validator\Text;

class BranchCursor extends Base
{
    public function isValid($value): bool
    {
        if (!$value instanceof Query) {
            return false;
        }

        $method = $value->getMethod();

        if (!\in_array($method, [Method::CursorAfter, Method::CursorBefore], true)) {
            $this->message = 'Invalid query method: ' . $method->value;
            return false;
        }

        $cursor = $value->getValue();

        $validator = new Text(256);
        if (!$validator->isValid($cursor)) {
            $this->message = 'Invalid cursor: ' . $validator->getDescription();
            return false;
        }

        return true;
    }

    public function getMethodType(): string
    {
        return self::METHOD_TYPE_CURSOR;
    }
}
