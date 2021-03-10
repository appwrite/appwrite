<?php

namespace Appwrite\GraphQL\Types;

use GraphQL\Language\AST\BooleanValueNode;
use GraphQL\Language\AST\FloatValueNode;
use GraphQL\Language\AST\IntValueNode;
use GraphQL\Language\AST\ListValueNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\ObjectValueNode;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Type\Definition\ScalarType;

class JsonType extends ScalarType
{
    public $name = 'Json';
    public $description =
        'The `JSON` scalar type represents JSON values as specified by
        [ECMA-404](http://www.ecma-international.org/publications/files/ECMA-ST/ECMA-404.pdf).';

    public function __construct(?string $name = null)
    {
        if ($name) {
            $this->name = $name;
        }
        parent::__construct();
    }

    public function parseValue($value)
    {
        return $this->identity($value);
    }

    public function serialize($value)
    {
        return $this->identity($value);
    }

    public function parseLiteral(Node $valueNode, ?array $variables = null)
    {
        switch ($valueNode) {
            case ($valueNode instanceof StringValueNode):
            case ($valueNode instanceof BooleanValueNode):
                return $valueNode->value;
            case ($valueNode instanceof IntValueNode):
            case ($valueNode instanceof FloatValueNode):
                return floatval($valueNode->value);
            case ($valueNode instanceof ObjectValueNode): {
                $value = [];
                foreach ($valueNode->fields as $field) {
                    $value[$field->name->value] = $this->parseLiteral($field->value);
                }
                return $value;
            }
            case ($valueNode instanceof ListValueNode):
                return array_map([$this, 'parseLiteral'], $valueNode->values);
            default:
                return null;
        }

    }

    private function identity($value)
    {
        return $value;
    }
}
