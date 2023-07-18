<?php

namespace Appwrite\Utopia\Database\Validator\Query;

use Appwrite\Utopia\Database\Validator\Query\Base;
use Utopia\Database\Query;
use Utopia\Http\Validator;

class Order extends Base
{
    /**
     * @var array
     */
    protected $schema = [];

    /**
     * Query constructor
     *
     */
    public function __construct(array $attributes = [])
    {
        foreach ($attributes as $attribute) {
            $this->schema[$attribute->getAttribute('key')] = $attribute->getArrayCopy();
        }
    }

    protected function isValidAttribute($attribute): bool
    {
        // Search for attribute in schema
        if (!isset($this->schema[$attribute])) {
            $this->message = 'Attribute not found in schema: ' . $attribute;
            return false;
        }

        return true;
    }

    /**
     * Is valid.
     *
     * Returns true if method is ORDER_ASC or ORDER_DESC and attributes are valid
     *
     * Otherwise, returns false
     *
     * @param Query $value
     *
     * @return bool
     */
    public function isValid($query): bool
    {
        $method = $query->getMethod();
        $attribute = $query->getAttribute();

        if ($method === Query::TYPE_ORDERASC || $method === Query::TYPE_ORDERDESC) {
            if ($attribute === '') {
                return true;
            }
            return $this->isValidAttribute($attribute);
        }

        return false;
    }

    public function getMethodType(): string
    {
        return self::METHOD_TYPE_ORDER;
    }
}
