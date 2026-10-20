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
 * Moments map to the cycle's slots in turn, so a full cycle reaches every inactive project once. With integer
 * sequences, block b of BLOCK sequences belongs to slot b % slots, which stays stable as the table grows.
 *
 * Before each block or page it enqueues, the handler checks the deletes queue: at BACKLOG messages or more it throws
 * Backlog, so the tick is not committed and the scheduler delivers it again. Moments already enqueued, and the blocks or
 * pages of the moment that was interrupted, are remembered, so a redelivery resumes where it stopped.
 */
final class Sweep
{
    public const int BLOCK = 100;

    public const int PAGE = 1000;

    public const int BACKLOG = 100;

    public const int INACTIVITY = 2592000;

    /** What a delete message carries for a project; the worker reloads the rest. */
    public const array ATTRIBUTES = ['$id', '$sequence', '$createdAt', 'teamId', 'teamInternalId', 'region', 'database'];

    /** Due time of the last moment fully enqueued; occurrences arrive oldest first. */
    private ?float $handled = null;

    /** Due time of the moment being enqueued, and how many of its blocks or pages were enqueued so far. */
    private ?float $current = null;

    private int $sent = 0;

    /** Blocks or pages reached in this delivery of the current moment. */
    private int $position = 0;

    public function __construct(
        private readonly Database $dbForPlatform,
        private readonly DeletePublisher $publisherForDeletes,
        private readonly string $region,
        private readonly Cycle $cycle,
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

            if ($this->current !== $due) {
                $this->current = $due;
                $this->sent = 0;
            }
            $this->position = 0;

            // Dues arrive in UTC; accessedAt is written in the default zone.
            $cutoff = \DateTime::createFromImmutable($occurrence->due->modify('-' . self::INACTIVITY . ' seconds'));
            $cutoff->setTimezone(new \DateTimeZone(\date_default_timezone_get()));
            $cutoff = DateTime::format($cutoff);

            $slot = $this->slot($occurrence->due);
            $region = Query::equal('region', [$this->region]);

            if ($this->dbForPlatform->getIdAttributeType() === ColumnType::Integer) {
                $this->blocks($slot, [
                    $region,
                    Query::or([
                        Query::lessThan('accessedAt', $cutoff),
                        Query::isNull('accessedAt'),
                    ]),
                ]);
            } else {
                $this->pages($slot, [$region, Query::lessThan('accessedAt', $cutoff)]);
                $this->pages($slot, [$region, Query::isNull('accessedAt')]);
            }

            $this->handled = $due;
        }
    }

    private function slot(\DateTimeImmutable $due): int
    {
        return \intdiv((int) $due->format('U'), $this->cycle->interval) % $this->cycle->slots;
    }

    /**
     * @param list<Query> $filters
     */
    private function blocks(int $slot, array $filters): void
    {
        $latest = $this->dbForPlatform->find('projects', [
            Query::orderDesc('$sequence'),
            Query::select(['$sequence']),
            Query::limit(1),
        ]);

        if ($latest === []) {
            return;
        }

        $last = \intdiv((int) $latest[0]->getSequence(), self::BLOCK);

        for ($block = $slot; $block <= $last; $block += $this->cycle->slots) {
            $start = $block * self::BLOCK;

            $this->enqueue($this->dbForPlatform->find('projects', [
                ...$filters,
                Query::between('$sequence', \max(1, $start), $start + self::BLOCK - 1),
                Query::select(self::ATTRIBUTES),
                Query::limit(self::BLOCK),
            ]));
        }
    }

    /**
     * Without integer sequences ownership is a hash of the ID, so every slice reads the whole inactive cohort, one
     * condition at a time so each read can use the region and accessedAt index.
     *
     * @param list<Query> $filters
     */
    private function pages(int $slot, array $filters): void
    {
        $cursor = null;

        do {
            $queries = [...$filters, Query::orderAsc('$sequence'), Query::select(self::ATTRIBUTES), Query::limit(self::PAGE)];
            if ($cursor !== null) {
                $queries[] = Query::cursorAfter($cursor);
            }

            $projects = $this->dbForPlatform->find('projects', $queries);
            if ($projects === []) {
                return;
            }

            $cursor = $projects[\count($projects) - 1];

            $this->enqueue(\array_filter($projects, fn (Document $project): bool => \crc32($project->getId()) % $this->cycle->slots === $slot));
        } while (\count($projects) === self::PAGE);
    }

    /**
     * @param array<Document> $projects
     *
     * @throws Backlog while the deletes queue is backlogged
     * @throws \RuntimeException when a message is not published
     */
    private function enqueue(array $projects): void
    {
        $this->position++;
        if ($projects === [] || $this->position <= $this->sent) {
            return;
        }

        $pending = $this->publisherForDeletes->getSize();
        if ($pending >= self::BACKLOG) {
            throw new Backlog($pending);
        }

        foreach ($projects as $project) {
            $published = $this->publisherForDeletes->enqueue(new DeleteMessage(
                project: $project,
                type: DELETE_TYPE_MAINTENANCE,
            ));

            if ($published === false) {
                throw new \RuntimeException('Failed to enqueue maintenance for project ' . $project->getId());
            }
        }

        $this->sent = $this->position;
    }
}
