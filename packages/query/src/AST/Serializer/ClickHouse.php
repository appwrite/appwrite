<?php

namespace Utopia\Query\AST\Serializer;

use Utopia\Query\AST\Serializer as BaseSerializer;
use Utopia\Query\StringLiteral;

class ClickHouse extends BaseSerializer
{
    public function __construct(StringLiteral $literal = StringLiteral::BackslashUtf8)
    {
        parent::__construct($literal);
    }
}
