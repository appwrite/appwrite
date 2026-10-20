<?php

namespace Appwrite\Maintenance;

use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Message\Maintenance as MaintenanceMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Query\Schema\ColumnType;
use Utopia\Schedule\Clock;
use Utopia\Schedule\Clock\System as SystemClock;
use Utopia\Span\Span;

/**
 * Enqueues maintenance for the slice of inactive projects one slot owns.
 *
 * With integer sequences, block b of BLOCK sequences belongs to slot b % slots, which stays stable as the table grows;
 * otherwise a project belongs to the slot its ID hashes to.
 *
 * Before each block or page it enqueues, the sweep waits while the deletes queue holds BACKLOG messages or more. A
 * crash or redeploy while it waits leaves the slot to the queue's reaper, and failing that to the next cycle: the
 * work is idempotent and every slot recurs once a cycle.
 */
final readonly class Sweep
{
    public const int BLOCK = 100;

    public const int PAGE = 1000;

    public const int BACKLOG = 100;

    public const int WAIT = 10;

    public const int INACTIVITY = 2592000;

    /** What a delete message carries for a project; the worker reloads the rest. */
    public const array ATTRIBUTES = ['$id', '$sequence', '$createdAt', 'teamId', 'teamInternalId', 'region', 'database'];

    public function __construct(
        private Database $dbForPlatform,
        private DeletePublisher $publisherForDeletes,
        private string $region,
        private Clock $clock = new SystemClock(),
    ) {
    }

    /**
     * @throws \RuntimeException when a message is not published
     */
    public function __invoke(MaintenanceMessage $message): void
    {
        // The scheduler keeps sending while the queue is backed up; a newer message for this slot supersedes this one.
        if ($this->clock->now()->getTimestamp() - $message->due > $message->period) {
            Span::add('maintenance.skipped', 'superseded');
            return;
        }

        // Dues are unix seconds; accessedAt is written in the default zone.
        $cutoff = new \DateTime('@' . ($message->due - self::INACTIVITY));
        $cutoff->setTimezone(new \DateTimeZone(\date_default_timezone_get()));
        $cutoff = DateTime::format($cutoff);

        $region = Query::equal('region', [$this->region]);

        if ($this->dbForPlatform->getIdAttributeType() === ColumnType::Integer) {
            $this->blocks($message, [
                $region,
                Query::or([
                    Query::lessThan('accessedAt', $cutoff),
                    Query::isNull('accessedAt'),
                ]),
            ]);
            return;
        }

        $this->pages($message, [$region, Query::lessThan('accessedAt', $cutoff)]);
        $this->pages($message, [$region, Query::isNull('accessedAt')]);
    }

    /**
     * @param list<Query> $filters
     */
    private function blocks(MaintenanceMessage $message, array $filters): void
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

        for ($block = $message->slot; $block <= $last; $block += $message->slots) {
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
    private function pages(MaintenanceMessage $message, array $filters): void
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

            $this->enqueue(\array_filter($projects, fn (Document $project): bool => \crc32($project->getId()) % $message->slots === $message->slot));
        } while (\count($projects) === self::PAGE);
    }

    /**
     * @param array<Document> $projects
     *
     * @throws \RuntimeException when a message is not published
     */
    private function enqueue(array $projects): void
    {
        if ($projects === []) {
            return;
        }

        while ($this->publisherForDeletes->getSize() >= self::BACKLOG) {
            $this->clock->sleep(self::WAIT);
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
    }
}
