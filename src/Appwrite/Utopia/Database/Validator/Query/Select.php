<?php

namespace Appwrite\Utopia\Database\Validator\Query;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;

class Select extends Base
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
            //$this->schema[$attribute->getAttribute('key')] = $attribute->getArrayCopy();
        }
    }

    /**
     * Is valid.
     *
     * Returns true if method is TYPE_SELECT selections are valid
     *
     * Otherwise, returns false
     *
     * @param $query
     * @return bool
     */
    public function isValid($query): bool
    {
        /* @var $query Query */

        if ($query->getMethod() === Query::TYPE_SELECT) {
            foreach ($query->getValues() as $attr) {
                var_dump($attr);
                // todo: Do some validations
                return true;
            }
        }

        return false;
    }

    public function getMethodType(): string
    {
        return self::METHOD_TYPE_SELECT;
    }
}
