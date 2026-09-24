<?php

namespace Tests\Query;

use Utopia\Query\Builder\Statement;

trait AssertsBindingCount
{
    protected function assertBindingCount(Statement $result): void
    {
        $placeholders = $this->countPlaceholders($result->query);
        $this->assertSame(
            $placeholders,
            count($result->bindings),
            "Placeholder count ({$placeholders}) != binding count (" . count($result->bindings) . ")\nQuery: {$result->query}"
        );
    }

    private function countPlaceholders(string $sql): int
    {
        // Match `?` but NOT `?|` or `?&` (PostgreSQL JSONB operators)
        // and NOT `??` (escaped question mark)
        return (int) preg_match_all('/(?<!\?)\?(?![|&?])/', $sql);
    }
}
