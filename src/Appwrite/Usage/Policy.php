<?php

namespace Appwrite\Usage;

use Utopia\Query\Query;

/**
 * Decides whether a caller may run a given usage query.
 *
 * Self-hosted has no plans, addons or reseller namespaces, so every
 * authenticated `usage.read` caller may ask for anything the storage holds and
 * these allow all of it. Cloud replaces the `usagePolicy` resource with a
 * subclass carrying its billing rules.
 *
 * Three hooks rather than one because the actions call them at different points:
 * access before the request is parsed, so an unauthorised caller is refused
 * rather than told whether their query was valid; the rest once the filters and
 * window are known.
 */
class Policy
{
    /**
     * @param array<int, string> $metrics Resolved metric names.
     */
    public function assertMetricAccess(array $metrics): void
    {
    }

    /**
     * @param array<int, string> $dimensions Requested break-down dimensions.
     * @param array<int, Query> $filters Parsed `queries[]` filters.
     */
    public function assertGeoDimensions(array $dimensions, array $filters): void
    {
    }

    /**
     * @param string $startAt Explicit range start, or '' when defaulted.
     */
    public function assertHistory(string $startAt): void
    {
    }
}
