<?php

namespace Utopia\Query\Schema;

use Utopia\Query\Exception\ValidationException;

final readonly class CheckConstraint
{
    public function __construct(
        public string $name,
        public string $expression,
    ) {
        if (! \preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new ValidationException('Invalid check constraint name: ' . $name);
        }
    }
}
