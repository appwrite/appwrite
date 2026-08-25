<?php

declare(strict_types=1);

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

/**
 * Everything the usage scheduler runs: one schedule per recently-active
 * project, plus the concurrency sampler.
 *
 * A project's occurrences are anchored on its own creation time, so the
 * fleet's sweeps sit at fixed but distinct offsets inside the interval
 * rather than all falling on the hour. The queue sees a steady trickle
 * instead of one burst of every project at once, and the offsets survive
 * restarts because they are derived from the project, not from boot time.
 */
class Usage implements Source, Changes
{
    /** The sampler is a schedule too, so it cannot be starved by the sweep. */
    public const string CONCURRENCY = 'concurrency';

    /**
     * Subqueries the project documents are decoded without. These are
     * relationship reads -- one query per document each -- and nothing
     * here touches them.
     *
     * Scoped to this Database instance, unlike Action::disableSubqueries(),
     * which reaches for Database::addFilter() and so rewrites the filter
     * for the whole process.
     */
    private const array SKIP = [
        'subQueryKeys', 'subQueryWebhooks', 'subQueryPlatforms', 'subQueryBlocks', 'subQueryDevKeys',
        'subQueryPaymentMethods', 'subQueryDNSRecords', 'subQueryOrganizationKeys', 'subQueryAccountKeys', 'subQueryAppSecrets',
    ];

    private const int PAGE = 1_000;

    public function __construct(
        private readonly Database $dbForPlatform,
        private readonly int $seconds,
        private readonly string $region,
    ) {
    }

    /**
     * @return iterable<Row>
     */
    #[\Override]
    public function snapshot(): iterable
    {
        yield new Row(id: self::CONCURRENCY, version: (string) $this->seconds);

        yield from $this->rows(null);
    }

    /**
     * @return iterable<Row>
     */
    #[\Override]
    public function since(\DateTimeImmutable $moment): iterable
    {
        // A project enters the set by being accessed, so the change feed is
        // the same query with a later floor. It cannot see one leaving --
        // that needs the window to be re-evaluated, which the periodic
        // snapshot does.
        yield from $this->rows($moment);
    }

    #[\Override]
    public function make(Row $row): Entry
    {
        if ($row->id === self::CONCURRENCY) {
            return new Entry(new Interval($this->seconds));
        }

        $project = $row->data;
        \assert($project instanceof Document);

        return new Entry(new Interval($this->seconds, $this->anchor($project)), $project);
    }

    /**
     * Recently-active projects in this region, paged.
     *
     * Throwing part-way through discards the batch rather than reading as a
     * mass removal, so a failed page costs a sync, not the schedule set.
     *
     * @return iterable<Row>
     */
    private function rows(?\DateTimeImmutable $since): iterable
    {
        $window = (new \DateTime())->sub(new \DateInterval('P1D'));
        $floor = $since instanceof \DateTimeImmutable
            ? max($window, \DateTime::createFromImmutable($since))
            : $window;

        $cursor = null;

        do {
            $queries = [
                Query::limit(self::PAGE),
                Query::greaterThanEqual('accessedAt', DateTime::format($floor)),
                Query::equal('region', [$this->region]),
                Query::orderAsc('$sequence'), // accessedAt can be updated during iteration
            ];

            if ($cursor instanceof Document) {
                $queries[] = Query::cursorAfter($cursor);
            }

            $projects = $this->page($queries);

            foreach ($projects as $project) {
                $updatedAt = (string) $project->getUpdatedAt();

                yield new Row(
                    // Config changes, not access, re-make the entry: accessedAt
                    // moves on every request and would re-make constantly.
                    id: (string) $project->getSequence(),
                    version: $updatedAt,
                    data: $project,
                    activeFrom: $this->moment($updatedAt),
                );
            }

            $cursor = \end($projects) ?: null;
        } while (\count($projects) === self::PAGE);
    }

    /**
     * @param array<Query> $queries
     * @return array<Document>
     */
    protected function page(array $queries): array
    {
        return $this->dbForPlatform->skipFilters(
            fn (): array => $this->dbForPlatform->find('projects', $queries),
            self::SKIP,
        );
    }

    /** Phase a project's grid to itself, so the fleet spreads. */
    private function anchor(Document $project): ?\DateTimeImmutable
    {
        return $this->moment((string) $project->getCreatedAt());
    }

    private function moment(string $stamp): ?\DateTimeImmutable
    {
        try {
            return $stamp === '' ? null : new \DateTimeImmutable($stamp);
        } catch (\Throwable) {
            return null;
        }
    }
}
