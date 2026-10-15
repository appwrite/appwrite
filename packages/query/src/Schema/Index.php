<?php

namespace Utopia\Query\Schema;

use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Schema\ClickHouse\IndexAlgorithm;

readonly class Index
{
    /**
     * @param  string[]  $columns
     * @param  array<string, int>  $lengths
     * @param  array<string, string>  $orders
     * @param  array<string, string>  $collations  Column-specific collations (column name => collation)
     * @param  list<string>  $rawColumns  Raw SQL expressions appended to the column list (bypass quoting)
     * @param  list<string|int|float>  $algorithmArgs  ClickHouse skip-index algorithm args
     *                                                  (e.g. [3] for set(3),
     *                                                  [0.01] for bloom_filter(0.01),
     *                                                  [4, 1024, 3, 0] for ngrambf_v1(n, size_bytes, hashes, seed))
     */
    public function __construct(
        public string $name,
        public array $columns,
        public IndexType $type = IndexType::Index,
        public array $lengths = [],
        public array $orders = [],
        public string $method = '',
        public string $operatorClass = '',
        public array $collations = [],
        public array $rawColumns = [],
        public ?IndexAlgorithm $algorithm = null,
        public array $algorithmArgs = [],
        public ?int $granularity = null,
    ) {
        // Only ClickHouse data-skipping indexes require an unquoted identifier
        // for the name; other dialects emit the name backtick-quoted, so
        // hyphens, dots, and other characters are valid there.
        if ($algorithm !== null && ! \preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new ValidationException('Invalid index name: ' . $name);
        }
        if ($columns === [] && $rawColumns === []) {
            throw new ValidationException('Index requires at least one column.');
        }
        if ($method !== '' && ! \preg_match('/^[A-Za-z0-9_]+$/', $method)) {
            throw new ValidationException('Invalid index method: ' . $method);
        }
        if ($operatorClass !== '' && ! \preg_match('/^[A-Za-z0-9_.]+$/', $operatorClass)) {
            throw new ValidationException('Invalid operator class: ' . $operatorClass);
        }
        foreach ($collations as $collation) {
            if (! \preg_match('/^[A-Za-z0-9_]+$/', $collation)) {
                throw new ValidationException('Invalid collation: ' . $collation);
            }
        }
        if ($granularity !== null && $granularity < 1) {
            throw new ValidationException('Index granularity must be >= 1.');
        }
        if ($algorithm !== null && $algorithmArgs !== [] && ! self::algorithmAcceptsArgs($algorithm)) {
            throw new ValidationException(
                $algorithm->value . ' does not accept algorithm arguments.'
            );
        }
    }

    /**
     * MinMax and Inverted are emitted without parentheses in ClickHouse DDL;
     * passing args to them would produce invalid SQL.
     */
    private static function algorithmAcceptsArgs(IndexAlgorithm $algorithm): bool
    {
        return match ($algorithm) {
            IndexAlgorithm::MinMax,
            IndexAlgorithm::Inverted => false,
            default => true,
        };
    }
}
