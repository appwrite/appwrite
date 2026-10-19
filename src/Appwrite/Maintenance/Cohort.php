<?php

namespace Appwrite\Maintenance;

use Utopia\Database\Query;

/**
 * Projects split by when they were last accessed. Together the cohorts cover every project in a region exactly once.
 * A project that changes cohort mid-cycle can wait up to about two cycles of its new cohort for maintenance.
 */
enum Cohort: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    /**
     * Filter sets swept one after the other. Inactive is two sets rather than one or() so each can use the region and
     * accessedAt index.
     *
     * @return list<list<Query>>
     */
    public function filters(string $region, string $cutoff): array
    {
        return match ($this) {
            self::Active => [
                [Query::equal('region', [$region]), Query::greaterThanEqual('accessedAt', $cutoff)],
            ],
            self::Inactive => [
                [Query::equal('region', [$region]), Query::lessThan('accessedAt', $cutoff)],
                [Query::equal('region', [$region]), Query::isNull('accessedAt')],
            ],
        };
    }
}
