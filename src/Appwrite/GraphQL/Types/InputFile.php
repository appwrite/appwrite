<?php

declare(strict_types=1);

namespace Appwrite\GraphQL\Types;

use GraphQL\Error\Error;
use GraphQL\Error\InvariantViolation;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Utils\Utils;
use Psr\Http\Message\UploadedFileInterface;
use UnexpectedValueException;

class InputFile extends ScalarType
{
    /**
     * @var string
     */
    public $name = 'Upload';

    /**
     * @var string
     */
    public $description
        = 'The `Upload` special type represents a file to be uploaded in the same HTTP request as specified by
 [graphql-multipart-request-spec](https://github.com/jaydenseric/graphql-multipart-request-spec).';

    /**
     * Serializes an internal value to include in a response.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public function serialize($value)
    {
        throw new InvariantViolation('`Upload` cannot be serialized');
    }

    /**
     * Parses an externally provided value (query variable) to use as an input.
     *
     * @param mixed $value
     **/
    public function parseValue($value)
    {
        return $value;
    }

    /**
     * Parses an externally provided literal value (hardcoded in GraphQL query) to use as an input.
     *
     * @param \GraphQL\Language\AST\Node $valueNode
     *
     * @return mixed
     */
    public function parseLiteral($valueNode, ?array $variables = null)
    {
        throw new Error('`Upload` cannot be hardcoded in query, be sure to conform to GraphQL multipart request specification. Instead got: ' . $valueNode->kind, $valueNode);
    }
}