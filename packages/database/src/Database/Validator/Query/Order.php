<?php

namespace Utopia\Database\Validator\Query;

use Utopia\Database\Document;
use Utopia\Database\Query;

class Order extends Base
{
    /**
     * @var array<int|string, true>
     */
    protected array $schema = [];

    /**
     * @param array<Document> $attributes
     * @param bool $supportForAttributes
     */
    public function __construct(array $attributes = [], protected bool $supportForAttributes = true)
    {
        foreach ($attributes as $attribute) {
            $this->schema[$attribute->getAttribute('key', $attribute->getAttribute('$id'))] = true;
        }
    }

    /**
     * @param string $attribute
     * @return bool
     */
    protected function isValidAttribute(string $attribute): bool
    {
        if (\str_contains($attribute, '.')) {
            // Check for special symbol `.`
            if (isset($this->schema[$attribute])) {
                return true;
            }

            // For relationships, just validate the top level.
            // Will validate each nested level during the recursive calls.
            $attribute = \explode('.', $attribute)[0];

            if (isset($this->schema[$attribute])) {
                $this->message = 'Cannot order by nested attribute: ' . $attribute;
                return false;
            }
        }

        // Search for attribute in schema
        if ($this->supportForAttributes && !isset($this->schema[$attribute])) {
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
     * @return bool
     */
    public function isValid($value): bool
    {
        if (!$value instanceof Query) {
            return false;
        }

        $method = $value->getMethod();
        $attribute = $value->getAttribute();

        if ($method === Query::TYPE_ORDER_ASC || $method === Query::TYPE_ORDER_DESC) {
            return $this->isValidAttribute($attribute);
        }

        if ($method === Query::TYPE_ORDER_RANDOM) {
            return true; // orderRandom doesn't need an attribute
        }

        return false;
    }

    public function getMethodType(): string
    {
        return self::METHOD_TYPE_ORDER;
    }
}
