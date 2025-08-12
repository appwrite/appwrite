<?php

namespace Appwrite\Utopia\Database\Validator\Query;

use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Query;

class Select extends Query
{
    protected array $schema = [];

    /**
     * @param Document[] $attributes
     */
    public function __construct(array $attributes)
    {
        foreach ($attributes as $attribute) {
            $this->schema[$attribute->getAttribute('key')] = $attribute->getAttribute('type');
        }
    }

    protected function isValidAttribute(string $attribute): bool
    {
        if (str_starts_with($attribute, '$')) {
            return true; // Allow system attributes
        }

        return array_key_exists($attribute, $this->schema);
    }

    public function isValid(mixed $query): bool
    {
        if (!$query instanceof \Utopia\Database\Query) {
            return false;
        }

        if ($query->getMethod() !== Query::TYPE_SELECT) {
            return false;
        }

        $values = $query->getValues();
        
        foreach ($values as $attribute) {
            if (!$this->isValidAttribute($attribute)) {
                $this->message = 'Query select is not valid: Attribute "' . $attribute . '" not found.';
                return false;
            }
        }

        return true;
    }

    public function getType(): string
    {
        return Query::TYPE_SELECT;
    }
}