<?php

namespace Appwrite\Maintenance;

use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Query\Schema\ColumnType;
use Utopia\Schedule\Occurrence;

/**
 * Scheduler handler that enqueues maintenance for the slice of inactive projects one due moment owns.
 *
 * Moments map to $slots slots in turn, so a full cycle of $slots moments reaches every inactive project once. With
 * integer sequences, block b of BLOCK sequences belongs to slot b % $slots, which stays stable as the table grows.
 *
 * While the deletes queue holds BACKLOG messages or more the handler throws Backlog: the tick is not committed and
 * the scheduler delivers it again. Moments already enqueued are remembered, so a redelivery skips them.
 */
final class Sweep
{
    public const int INTERVAL = 300;

    public const int BLOCK = 100;

    public const int BACKLOG = 100;

    public const int INACTIVITY = 2592000;

    /** Due time of the last moment fully enqueued; occurrences arrive oldest first. */
    private ?float $handled = null;

    public function __construct(
        private readonly Database $dbForPlatform,
        private readonly DeletePublisher $publisherForDeletes,
        private readonly string $region,
        private readonly int $interval,
        private readonly int $slots,
    ) {
    }

    /**
     * @param list<Occurrence> $occurrences
     *
     * @throws Backlog while the deletes queue is backlogged
     */
    public function __invoke(array $occurrences): void
    {
        foreach ($occurrences as $occurrence) {
            $due = (float) $occurrence->due->format('U.u');
            if ($this->handled !== null && $due <= $this->handled) {
                continue;
            }

            $pending = $this->publisherForDeletes->getSize();
            if ($pending >= self::BACKLOG) {
                throw new Backlog($pending);
            }

            $cutoff = \DateTime::createFromImmutable($occurrence->due->modify('-' . self::INACTIVITY . ' seconds'));
            $cutoff->setTimezone(new \DateTimeZone(\date_default_timezone_get()));

            $filters = [
                Query::equal('region', [$this->region]),
                Query::or([
                    Query::lessThan('accessedAt', DateTime::format($cutoff)),
                    Query::isNull('accessedAt'),
                ]),
            ];

            $slot = $this->slot($occurrence->due);

            if ($this->dbForPlatform->getIdAttributeType() === ColumnType::Integer) {
                $this->blocks($slot, $filters);
            } else {
                $this->pages($slot, $filters);
            }

            $this->handled = $due;
        }
    }

    public function slot(\DateTimeImmutable $due): int
    {
        return \intdiv((int) $due->format('U'), $this->interval) % $this->slots;
    }

    /**
     * @param list<Query> $filters
     */
    private function blocks(int $slot, array $filters): void
    {
        $latest = $this->find([
            Query::orderDesc('$sequence'),
            Query::select(['$sequence']),
            Query::limit(1),
        ]);

        if ($latest === []) {
            return;
        }

        $last = \intdiv((int) $latest[0]->getSequence(), self::BLOCK);

        for ($block = $slot; $block <= $last; $block += $this->slots) {
            $start = $block * self::BLOCK;

            $this->enqueue($this->find([
                ...$filters,
                Query::between('$sequence', \max(1, $start), $start + self::BLOCK - 1),
                Query::limit(self::BLOCK),
            ]));
        }
    }

    /**
     * Without integer sequences ownership is a hash of the ID, so every slice reads the whole inactive cohort.
     *
     * @param list<Query> $filters
     */
    private function pages(int $slot, array $filters): void
    {
        $cursor = null;

        do {
            $queries = [...$filters, Query::orderAsc('$sequence'), Query::limit(self::BLOCK)];
            if ($cursor !== null) {
                $queries[] = Query::cursorAfter($cursor);
            }

            $projects = $this->find($queries);
            if ($projects === []) {
                return;
            }

            $cursor = $projects[\count($projects) - 1];

            $this->enqueue(\array_filter($projects, fn (Document $project): bool => \crc32($project->getId()) % $this->slots === $slot));
        } while (\count($projects) === self::BLOCK);
    }

    /**
     * @param list<Query> $queries
     * @return array<Document>
     */
    private function find(array $queries): array
    {
        return $this->dbForPlatform->skipFilters(
            fn (): array => $this->dbForPlatform->find('projects', $queries),
            APP_PROJECTS_SUBQUERIES
        );
    }

    /**
     * @param array<Document> $projects
     */
    private function enqueue(array $projects): void
    {
        foreach ($projects as $project) {
            $this->publisherForDeletes->enqueue(new DeleteMessage(
                project: $project,
                type: DELETE_TYPE_MAINTENANCE,
            ));
        }
    }
}
