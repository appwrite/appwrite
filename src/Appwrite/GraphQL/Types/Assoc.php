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
class Assoc extends Json
{
    public $name = 'Assoc';
    public $description = 'The `Assoc` scalar type represents associative array values.';

    public function serialize($value)
    {
        if (\is_string($value)) {
            return $value;
        }

        return \json_encode($value);
    }

    public function parseValue($value)
    {
        if (\is_array($value)) {
            return $value;
        }

        return \json_decode($value, true);
    }

    public function parseLiteral(Node $valueNode, ?array $variables = null)
    {
        return \json_decode($valueNode->value, true);
    }
}
