<?php

namespace Appwrite\GraphQL\Types;

use GraphQL\Language\AST\IntValueNode;
use GraphQL\Language\AST\ListValueNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\ObjectValueNode;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Utils\AST;

// https://github.com/webonyx/graphql-php/issues/129#issuecomment-309366803
class Json extends ScalarType
{
    public string $name = 'Json';
    public ?string $description = 'The `JSON` scalar type represents JSON values as specified by
        [ECMA-404](https://www.ecma-international.org/publications/files/ECMA-ST/ECMA-404.pdf).';

    public function serialize($value)
    {
        return $value;
    }

    public function parseValue($value)
    {
        return $value;
    }

    public function parseLiteral(Node $valueNode, ?array $variables = null)
    {
        if ($valueNode instanceof IntValueNode) {
            // Match JSON input: retain native integers and use floats beyond PHP's range.
            return \json_decode($valueNode->value, flags: JSON_THROW_ON_ERROR);
        }

        if ($valueNode instanceof ListValueNode) {
            $values = [];
            foreach ($valueNode->values as $node) {
                $values[] = self::parseLiteral($node, $variables);
            }
            return $values;
        }

        if ($valueNode instanceof ObjectValueNode) {
            $values = [];
            foreach ($valueNode->fields as $field) {
                $values[$field->name->value] = self::parseLiteral($field->value, $variables);
            }
            return $values;
        }

        return AST::valueFromASTUntyped($valueNode, $variables);
    }
}
