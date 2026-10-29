<?php

namespace Appwrite\Deployment\Retention;

use Utopia\Database\Query;

/**
 * One reason deployments retire for, and the queries that select them on top of a resource's own filters.
 */
readonly class Pass
{
    /**
     * @param list<Query> $queries
     */
    public function __construct(
        public Reason $reason,
        public array $queries,
    ) {
    }
}
