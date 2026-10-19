<?php

namespace Appwrite\Maintenance;

use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Message\ProjectContext;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Query\Schema\ColumnType;
use Utopia\Schedule\Clock;

/**
 * Enqueues maintenance for the projects of one cohort that one slot owns.
 *
 * With integer sequences, projects are grouped in blocks of BLOCK sequences and block b belongs to slot b % count.
 * Other sequence types are spread by a hash of the project ID, which reads the whole cohort on every tick.
 *
 * With a deadline the sweep waits while the deletes queue holds BACKLOG messages or more, and drops what it has not
 * reached once the deadline passes. The size counts pending messages only, from user deletes as well; BACKLOG keeps
 * the cohort's own share of the queue small so user deletes stay responsive.
 */
final readonly class Sweep
{
    public const int BLOCK = 1000;

    public const int BACKLOG = 100;

    public const int INACTIVITY = 2592000;

    public function __construct(
        private Database $dbForPlatform,
        private DeletePublisher $publisherForDeletes,
        private Clock $clock,
        private string $region,
    ) {
    }

    public static function cutoff(Clock $clock): string
    {
        $cutoff = \DateTime::createFromImmutable($clock->now()->modify('-' . self::INACTIVITY . ' seconds'));
        $cutoff->setTimezone(new \DateTimeZone(\date_default_timezone_get()));

        return DateTime::format($cutoff);
    }

    /**
     * The slot index that owns $project out of $slots, by the same rule the sweep selects with.
     */
    public static function owner(Document $project, int $slots, ColumnType $idType): int
    {
        if ($slots <= 1) {
            return 0;
        }

        if ($idType === ColumnType::Integer) {
            return \intdiv((int) $project->getSequence(), self::BLOCK) % $slots;
        }

        return \crc32($project->getId()) % $slots;
    }

    /**
     * Without a deadline the sweep neither waits for the queue nor drops anything.
     */
    public function run(Cohort $cohort, Slot $slot, string $cutoff, ?int $deadline = null, ?string $project = null): Outcome
    {
        $filters = $cohort->filters($this->region, $cutoff);
        if ($project !== null) {
            $filters = \array_map(fn (array $queries): array => [...$queries, Query::equal('$id', [$project])], $filters);
        }

        $idType = $this->dbForPlatform->getIdAttributeType();

        if ($slot->count === 1 || $project !== null) {
            return $this->pages($cohort, $slot, $filters, $deadline, null);
        }

        if ($idType === ColumnType::Integer) {
            return $this->blocks($cohort, $slot, $filters, $deadline);
        }

        return $this->pages($cohort, $slot, $filters, $deadline, $idType);
    }

    /**
     * Walks the slot's blocks, starting one block later each cycle so a dropped tail is not always the same.
     *
     * @param list<list<Query>> $filters
     */
    private function blocks(Cohort $cohort, Slot $slot, array $filters, ?int $deadline): Outcome
    {
        $latest = $this->dbForPlatform->skipFilters(fn (): array => $this->dbForPlatform->find('projects', [
            Query::orderDesc('$sequence'),
            Query::limit(1),
            Query::select(['$sequence']),
        ]), APP_PROJECTS_SUBQUERIES);

        $blocks = [];
        if ($latest !== []) {
            $last = \intdiv((int) $latest[0]->getSequence(), self::BLOCK);
            for ($block = $slot->index; $block <= $last; $block += $slot->count) {
                $blocks[] = $block;
            }
        }

        if ($blocks !== []) {
            $offset = $slot->cycle % \count($blocks);
            $blocks = [...\array_slice($blocks, $offset), ...\array_slice($blocks, 0, $offset)];
        }

        $reached = 0;
        $reads = 0;
        $enqueued = 0;
        $waited = 0;

        foreach ($blocks as $block) {
            if (!$this->admit($deadline, $waited)) {
                break;
            }

            $start = $block * self::BLOCK;

            foreach ($filters as $queries) {
                /** @var array<Document> $projects */
                $projects = $this->dbForPlatform->skipFilters(fn (): array => $this->dbForPlatform->find('projects', [
                    ...$queries,
                    Query::between('$sequence', \max(1, $start), $start + self::BLOCK - 1),
                    Query::select(ProjectContext::ATTRIBUTES),
                    Query::limit(self::BLOCK),
                ]), APP_PROJECTS_SUBQUERIES);

                $enqueued += $this->enqueue($projects);
                $reads++;
            }

            $reached++;
        }

        $dropped = \count($blocks) - $reached;

        return new Outcome($cohort, $slot, $reads, $enqueued, $waited, $dropped, $dropped > 0);
    }

    /**
     * Walks each filter set in pages ordered by $sequence, reversed on odd cycles so a dropped tail is reached first
     * the next time. With $idType set, keeps only the projects the slot owns by hash.
     *
     * @param list<list<Query>> $filters
     */
    private function pages(Cohort $cohort, Slot $slot, array $filters, ?int $deadline, ?ColumnType $idType): Outcome
    {
        $reversed = $slot->cycle % 2 !== 0;
        $order = $reversed ? Query::orderDesc('$sequence') : Query::orderAsc('$sequence');
        if ($reversed) {
            $filters = \array_reverse($filters);
        }

        $reads = 0;
        $enqueued = 0;
        $waited = 0;

        foreach ($filters as $queries) {
            $cursor = null;

            while (true) {
                if (!$this->admit($deadline, $waited)) {
                    return new Outcome($cohort, $slot, $reads, $enqueued, $waited, 0, true);
                }

                $page = [
                    ...$queries,
                    $order,
                    Query::select(ProjectContext::ATTRIBUTES),
                    Query::limit(self::BLOCK),
                ];
                if ($cursor !== null) {
                    $page[] = Query::cursorAfter($cursor);
                }

                /** @var array<Document> $projects */
                $projects = $this->dbForPlatform->skipFilters(fn (): array => $this->dbForPlatform->find('projects', $page), APP_PROJECTS_SUBQUERIES);
                $reads++;

                if ($projects === []) {
                    break;
                }

                $full = \count($projects) === self::BLOCK;
                $cursor = $projects[\count($projects) - 1];

                if ($idType !== null) {
                    $projects = \array_filter($projects, fn (Document $project): bool => self::owner($project, $slot->count, $idType) === $slot->index);
                }

                $enqueued += $this->enqueue($projects);

                if (!$full) {
                    break;
                }
            }
        }

        return new Outcome($cohort, $slot, $reads, $enqueued, $waited, 0, false);
    }

    /**
     * Whether the sweep may take the next unit of work, waiting while the deletes queue is backlogged.
     */
    private function admit(?int $deadline, int &$waited): bool
    {
        if ($deadline === null) {
            return true;
        }

        while ($this->publisherForDeletes->getSize() >= self::BACKLOG && $this->clock->now()->getTimestamp() < $deadline) {
            $this->clock->sleep(1);
            $waited++;
        }

        return $this->clock->now()->getTimestamp() < $deadline;
    }

    /**
     * @param array<Document> $projects
     */
    private function enqueue(array $projects): int
    {
        $enqueued = 0;

        foreach ($projects as $project) {
            $result = $this->publisherForDeletes->enqueue(new DeleteMessage(
                project: $project,
                type: DELETE_TYPE_MAINTENANCE,
            ));

            if ($result !== false) {
                $enqueued++;
            }
        }

        return $enqueued;
    }
}
