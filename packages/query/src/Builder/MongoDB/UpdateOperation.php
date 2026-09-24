<?php

namespace Utopia\Query\Builder\MongoDB;

final readonly class UpdateOperation
{
    /**
     * @param array<string, mixed> $fields field => value (or field => modifier dict, for push-with-each)
     */
    public function __construct(
        public UpdateOperator $operator,
        public array $fields,
    ) {
    }
}
