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

// https://github.com/webonyx/graphql-php/issues/129#issuecomment-309366803
class Json extends ScalarType
{
    public $name = 'Json';
    public $description =
        'The `JSON` scalar type represents JSON values as specified by
        [ECMA-404](https://www.ecma-international.org/publications/files/ECMA-ST/ECMA-404.pdf).';

    public function serialize($value)
    {
        return \json_encode($value);
    }

    public function parseValue($value)
    {
        return \json_decode($value, associative: true);
    }

    public function parseLiteral(Node $valueNode, ?array $variables = null)
    {
        switch ($valueNode) {
            case $valueNode instanceof StringValueNode:
            case $valueNode instanceof BooleanValueNode:
                return $valueNode->value;
            case $valueNode instanceof IntValueNode:
            case $valueNode instanceof FloatValueNode:
                return floatval($valueNode->value);
            case $valueNode instanceof ObjectValueNode:
                $value = [];
                foreach ($valueNode->fields as $field) {
                    $value[$field->name->value] =
                        $this->parseLiteral($field->value);
                }
                return $value;
            case ($valueNode instanceof ListValueNode):
                return array_map([$this, 'parseLiteral'], $valueNode->values);
            default:
                return null;
        }
    }
}
