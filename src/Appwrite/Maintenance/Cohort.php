<?php

namespace Appwrite\Maintenance;

use Utopia\Database\Query;

/**
 * Which projects a cycle maintains: those accessed since the inactivity cutoff, or those not accessed since.
 */
enum Cohort: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    /**
     * The accessedAt conditions selecting the cohort at $cutoff. A sweep reads them one at a time, so each read can
     * use the region and accessedAt index.
     *
     * @return list<Query>
     */
    public function conditions(string $cutoff): array
    {
        return match ($this) {
            self::Active => [Query::greaterThanEqual('accessedAt', $cutoff)],
            self::Inactive => [Query::lessThan('accessedAt', $cutoff), Query::isNull('accessedAt')],
        };
    }
}
