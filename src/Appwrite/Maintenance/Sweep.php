<?php

namespace Appwrite\Maintenance;

use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Message\Maintenance as MaintenanceMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Event\Publisher\Maintenance as MaintenancePublisher;
use Utopia\Console\Console;
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
 * Before each block or page it enqueues, the sweep checks the deletes queue. At BACKLOG messages or more it waits WAIT
 * seconds once, and if the queue is still backed up it sends the slot's message to the maintenance queue again with a
 * cursor at that block or page and returns, so no job outlives a short wait. A message older than one period is
 * skipped, since by then the slot's next run has superseded it.
 *
 * A database or broker error, or a refused publish, throws and parks the job on the failed list. It is not resent:
 * the work is idempotent and every slot recurs once a cycle.
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
        private MaintenancePublisher $publisherForMaintenance,
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

        $region = Query::equal('region', [$message->region]);

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

        // One condition at a time, so each read can use the region and accessedAt index.
        $passes = [Query::lessThan('accessedAt', $cutoff), Query::isNull('accessedAt')];
        for ($pass = $message->pass; $pass < \count($passes); $pass++) {
            if (!$this->pages($message, $pass, [$region, $passes[$pass]], $pass === $message->pass ? $message->cursor : '')) {
                return;
            }
        }
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

        // The first block the slot owns past the cursor.
        $next = \intdiv((int) $message->cursor + 1, self::BLOCK);
        $block = $next + (($message->slot - $next % $message->slots) + $message->slots) % $message->slots;

        for (; $block <= $last; $block += $message->slots) {
            $start = $block * self::BLOCK;

            $projects = $this->dbForPlatform->find('projects', [
                ...$filters,
                Query::between('$sequence', \max(1, $start), $start + self::BLOCK - 1),
                Query::select(self::ATTRIBUTES),
                Query::limit(self::BLOCK),
            ]);

            if ($projects !== [] && $this->deferred($message, 0, (string) \max(0, $start - 1))) {
                return;
            }

            $this->enqueue($projects);
        }
    }

    /**
     * Without integer sequences ownership is a hash of the ID, so every slice reads the whole inactive cohort. The
     * cursor is the ID of the last project read; if that project has since been deleted the pass starts over.
     *
     * @param list<Query> $filters
     *
     * @return bool false when the sweep deferred
     */
    private function pages(MaintenanceMessage $message, int $pass, array $filters, string $cursor): bool
    {
        $queries = [...$filters, Query::orderAsc('$sequence'), Query::select(self::ATTRIBUTES), Query::limit(self::PAGE)];

        $after = $cursor === '' ? new Document() : $this->dbForPlatform->getDocument('projects', $cursor, [Query::select(['$id', '$sequence'])]);

        do {
            $projects = $this->dbForPlatform->find('projects', $after->isEmpty() ? $queries : [...$queries, Query::cursorAfter($after)]);
            if ($projects === []) {
                return true;
            }

            $owned = \array_filter($projects, fn (Document $project): bool => \crc32($project->getId()) % $message->slots === $message->slot);
            if ($owned !== [] && $this->deferred($message, $pass, $after->getId())) {
                return false;
            }

            $this->enqueue($owned);
            $after = $projects[\count($projects) - 1];
        } while (\count($projects) === self::PAGE);

        return true;
    }

    /**
     * Waits once for a backed-up deletes queue, and if it is still backed up sends the slot again to resume at $cursor.
     *
     * @throws \RuntimeException when the message is not sent again
     */
    private function deferred(MaintenanceMessage $message, int $pass, string $cursor): bool
    {
        $pending = $this->publisherForDeletes->getSize();
        if ($pending >= self::BACKLOG) {
            $this->clock->sleep(self::WAIT);
            $pending = $this->publisherForDeletes->getSize();
        }

        if ($pending < self::BACKLOG) {
            return false;
        }

        $published = $this->publisherForMaintenance->enqueue(new MaintenanceMessage(
            slot: $message->slot,
            slots: $message->slots,
            due: $message->due,
            period: $message->period,
            region: $message->region,
            pass: $pass,
            cursor: $cursor,
        ));

        if ($published === false) {
            throw new \RuntimeException('Failed to defer maintenance for slot due at ' . $message->due);
        }

        Span::add('maintenance.deferred', $pending);
        Console::info('maintenance: deferred slot ' . $message->slot . ' due at ' . $message->due . ' behind ' . $pending . ' pending deletes');

        return true;
    }

    /**
     * @param array<Document> $projects
     *
     * @throws \RuntimeException when a message is not published
     */
    private function enqueue(array $projects): void
    {
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
