<?php

namespace Appwrite\Schedule\Source;

use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Schedule\Changes;
use Utopia\Schedule\Source;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Source\Row;
use Utopia\Schedule\Trigger\Interval;
use Utopia\Schedule\Trigger\Shifted;
use Utopia\System\System;

/**
 * What the stats-resources task samples: the region's realtime concurrency,
 * and the resource counts of every project accessed in the last day.
 *
 * Each project takes a slot derived from its id inside the interval, so a
 * region's counts spread across the hour instead of bursting at the top of it.
 */
final class Stats implements Source, Changes
{
    public const string CONCURRENCY = 'concurrency';

    /**
     * @param list<string> $subqueries project decode filters to skip; only the id is read
     */
    public function __construct(
        private readonly Database $dbForPlatform,
        private readonly int $interval,
        private readonly array $subqueries = APP_PROJECTS_SUBQUERIES,
    ) {
    }

    #[\Override]
    public function snapshot(): iterable
    {
        yield new Row(id: self::CONCURRENCY, version: '');
        yield from $this->projects((new \DateTimeImmutable())->sub(new \DateInterval('P1D')));
    }

    #[\Override]
    public function since(\DateTimeImmutable $moment): iterable
    {
        yield from $this->projects($moment);
    }

    #[\Override]
    public function make(Row $row): Entry
    {
        if ($row->id === self::CONCURRENCY) {
            return new Entry(new Interval($this->interval));
        }

        // The worker reloads the project by id, so the payload carries no more.
        return new Entry(
            new Shifted(new Interval($this->interval), \abs(\crc32($row->id)) % $this->interval),
            new Document(['$id' => $row->id]),
        );
    }

    /**
     * @return iterable<Row>
     */
    private function projects(\DateTimeImmutable $accessedSince): iterable
    {
        $limit = 1000;
        $sum = $limit;
        $latest = null;

        while ($sum === $limit) {
            $queries = [
                Query::limit($limit),
                Query::equal('region', [System::getEnv('_APP_REGION', 'default')]),
                Query::greaterThanEqual('accessedAt', DateTime::format(\DateTime::createFromImmutable($accessedSince))),
                Query::orderAsc('$sequence'), // accessedAt can be updated during iteration
            ];

            if ($latest !== null) {
                $queries[] = Query::cursorAfter($latest);
            }

            $projects = $this->dbForPlatform->skipFilters(
                fn () => $this->dbForPlatform->find('projects', $queries),
                $this->subqueries
            );
            $sum = \count($projects);

            foreach ($projects as $project) {
                yield new Row(id: $project->getId(), version: '');
            }

            $latest = \end($projects) ?: null;
        }
    }
}
