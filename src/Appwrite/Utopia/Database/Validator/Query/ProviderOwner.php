<?php

namespace Appwrite\Utopia\Database\Validator\Query;

use Utopia\Database\Query;
use Utopia\Database\Validator\Query\Base;
use Utopia\Validator\Text;

class ProviderOwner extends Base
{
    public function getMethodType(): string
    {
        return self::METHOD_TYPE_FILTER;
    }

    public function isValid($value): bool
    {
        if (!$value instanceof Query) {
            $this->message = 'Query must be an instance of Query';
            return false;
        }

        if ($value->getMethod() !== Query::TYPE_EQUAL) {
            $this->message = 'Only equal queries are supported for owner';
            return false;
        }

        if ($value->getAttribute() !== 'owner') {
            $this->message = 'Only owner can be queried';
            return false;
        }

        if (\count($value->getValues()) !== 1) {
            $this->message = 'Owner query must have exactly one value';
            return false;
        }

        if (!(new Text(256))->isValid($value->getValue())) {
            $this->message = 'Owner query value must be a string with a maximum length of 256 characters';
            return false;
        }

        return true;
    }
}
