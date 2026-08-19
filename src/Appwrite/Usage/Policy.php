<?php

namespace Appwrite\Usage;

use Utopia\Query\Query;

/**
 * Decides whether a caller may run a given usage query.
 *
 * Self-hosted has no plans, addons, or reseller namespaces, so every
 * authenticated `usage.read` caller may ask for anything the storage holds and
 * this implementation allows all of it. Cloud replaces the `usagePolicy`
 * resource with a subclass that enforces plan history limits, premium geo
 * addons, and its affiliate metric carve-out.
 */
class Policy
{
    /**
     * Reject a query the caller is not entitled to make, before it reaches
     * ClickHouse.
     *
     * @param array<int, string> $metrics Resolved metric names.
     * @param array<int, string> $dimensions Requested break-down dimensions.
     * @param array<int, Query> $filters Parsed `queries[]` filters.
     * @param string $startAt Explicit range start, or '' when defaulted.
     */
    public function assert(array $metrics, array $dimensions, array $filters, string $startAt): void
    {
    }
}
