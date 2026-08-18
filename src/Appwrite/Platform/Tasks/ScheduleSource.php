<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Schedule\Changes;
use Utopia\Schedule\Source;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Source\Row;
use Utopia\System\System;

/**
 * The platform `schedules` collection as a source of truth for
 * utopia-php/schedule.
 *
 * Reading the collection is the same work for every kind of schedule — page
 * the rows for this region and resource type, resolve the project, load the
 * resource, drop what no longer exists — so it lives here once and each task
 * constructs one. What differs per task is passed in: how to load its
 * resource, and what its stored `schedule` attribute means.
 */
final class ScheduleSource implements Source, Changes
{
    /** @var array<string, Document> projects already resolved, by project id */
    private array $projects = [];

    /** @var array<string, string> the version of each live schedule, by id, as last read */
    private array $live = [];

    private int $snapshotted = 0;

    /**
     * @param callable(Document): Database $getProjectDB
     * @param callable(Document, string, string): bool $isResourceBlocked
     * @param \Closure(Database, array<string, mixed>): Document $resource how to load the
     *        resource a schedule points at
     * @param \Closure(array<string, mixed>): Entry $entry what the stored schedule means,
     *        given the assembled schedule array
     * @param int $recency seconds within which a changed row is treated as new, so its first
     *        occurrences are covered even though the committed window has passed them
     */
    public function __construct(
        private readonly Database $dbForPlatform,
        private readonly mixed $getProjectDB,
        private readonly mixed $isResourceBlocked,
        private readonly string $resourceType,
        private readonly string $collectionId,
        private readonly \Closure $resource,
        private readonly \Closure $entry,
        private readonly int $recency,
    ) {
    }

    /**
     * Every active schedule of this type. The full desired set is what
     * converges deletions: a row that has disappeared cannot be reported by
     * any change feed.
     */
    #[\Override]
    public function snapshot(): iterable
    {
        $this->snapshotted = 0;

        // A snapshot is the whole truth: the live view is rebuilt, not added to.
        $this->live = [];

        foreach ($this->rows(null) as $row) {
            $this->snapshotted++;

            yield $row;
        }
    }

    /**
     * How many active rows the last full snapshot reported. A boot-time
     * figure for the log, not a live count: rows the scheduler went on to
     * reject (missing project, blocked or orphaned resource) are included.
     */
    public function snapshotted(): int
    {
        return $this->snapshotted;
    }

    /**
     * Whether this exact definition is still the one the source reports, as
     * fresh as the last read of the collection.
     */
    public function isLive(string $id, string $version): bool
    {
        return ($this->live[$id] ?? null) === $version;
    }

    /**
     * Only what changed, which is what keeps a short sync cadence cheap.
     * Rows that were just disabled come through with `active: false`, so they
     * are dropped without waiting for the next snapshot.
     */
    #[\Override]
    public function since(\DateTimeImmutable $moment): iterable
    {
        yield from $this->rows($moment);
    }

    /**
     * Turn a row into a runnable schedule. Called only for rows that are new
     * or whose definition changed, which is what keeps the project and
     * resource loads off the common path.
     *
     * @throws \InvalidArgumentException when the row cannot be scheduled
     */
    #[\Override]
    public function make(Row $row): Entry
    {
        $document = $row->data;
        if (!$document instanceof Document) {
            throw new \InvalidArgumentException('Schedule row carries no document');
        }

        $schedule = [
            '$sequence' => $document->getSequence(),
            '$id' => $document->getId(),
            'projectId' => $document->getAttribute('projectId'),
            'resourceId' => $document->getAttribute('resourceId'),
            'resourceType' => $document->getAttribute('resourceType'),
            'schedule' => $document->getAttribute('schedule'),
            'active' => $document->getAttribute('active'),
            'resourceUpdatedAt' => $document->getAttribute('resourceUpdatedAt'),
            'data' => $document->getAttribute('data', []),
        ];

        $project = $this->project((string) $schedule['projectId']);
        if ($project->isEmpty()) {
            throw new \InvalidArgumentException("Project not found: {$schedule['projectId']}");
        }

        if (($this->isResourceBlocked)($project, $this->collectionId, $schedule['resourceId'])) {
            throw new \InvalidArgumentException("Resource blocked: {$schedule['resourceId']}");
        }

        $schedule['project'] = $project;
        $schedule['resource'] = ($this->resource)(($this->getProjectDB)($project), $schedule);

        if ($schedule['resource']->isEmpty()) {
            // The resource is gone for good, so drop the orphaned schedule
            // rather than resolving it again on every snapshot.
            $this->deleteOrphan($document->getId());

            throw new \InvalidArgumentException("Resource not found: {$schedule['resourceId']}");
        }

        return ($this->entry)($schedule);
    }

    /**
     * Touch a project's access stamp, throttled to once per
     * APP_PROJECT_ACCESS. Shared because every task that dispatches on behalf
     * of a project owes it the same bookkeeping.
     */
    public static function touchProject(Document $project, Database $dbForPlatform): void
    {
        if ($project->isEmpty() || $project->getId() === 'console') {
            return;
        }

        $accessedAt = $project->getAttribute('accessedAt', 0);
        if (DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_PROJECT_ACCESS)) > $accessedAt) {
            $now = DateTime::now();
            $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
                'accessedAt' => $now
            ]));
            $project->setAttribute('accessedAt', $now);
        }
    }

    /**
     * @return iterable<Row>
     */
    private function rows(?\DateTimeImmutable $since): iterable
    {
        // Temporarly accepting both 'fra' and 'default'
        // When all migrated, only use _APP_REGION with 'default' as default value
        $regions = [System::getEnv('_APP_REGION', 'default')];
        if (!\in_array('default', $regions)) {
            $regions[] = 'default';
        }

        $limit = 10_000;
        $sum = $limit;
        $latest = null;

        while ($sum === $limit) {
            $queries = [
                Query::limit($limit),
                Query::equal('region', $regions),
                Query::equal('resourceType', [$this->resourceType]),
            ];

            if ($since === null) {
                $queries[] = Query::equal('active', [true]);
            } else {
                $queries[] = Query::greaterThanEqual('resourceUpdatedAt', DateTime::format(\DateTime::createFromImmutable($since)));
            }

            if ($latest !== null) {
                $queries[] = Query::cursorAfter($latest);
            }

            $schedules = $this->dbForPlatform->find('schedules', $queries);
            $sum = \count($schedules);

            foreach ($schedules as $schedule) {
                $id = (string) $schedule->getSequence();
                $version = (string) $schedule->getAttribute('resourceUpdatedAt', '');

                if ($schedule->getAttribute('active', false)) {
                    $this->live[$id] = $version;
                } else {
                    unset($this->live[$id]);
                }

                yield new Row(
                    id: $id,
                    version: $version,
                    data: $schedule,
                    active: (bool) $schedule->getAttribute('active', false),
                    activeFrom: $this->activeFrom($schedule),
                );
            }

            $latest = \end($schedules) ?: null;
        }
    }

    /**
     * When a recently changed definition takes effect, so its first
     * occurrences are not skipped by a window that has already passed them.
     * Older rows ride the watermark instead of replaying on startup.
     */
    private function activeFrom(Document $schedule): ?\DateTimeImmutable
    {
        $updatedAt = $schedule->getAttribute('resourceUpdatedAt');
        if (!\is_string($updatedAt) || $updatedAt === '') {
            return null;
        }

        try {
            $changed = new \DateTimeImmutable($updatedAt);
        } catch (\Throwable) {
            return null;
        }

        return $changed > (new \DateTimeImmutable())->modify("-{$this->recency} seconds") ? $changed : null;
    }

    private function project(string $projectId): Document
    {
        if (isset($this->projects[$projectId])) {
            return $this->projects[$projectId];
        }

        // The project's subquery attributes cost one query each, per project,
        // and no schedule task reads them: the only attributes used here are
        // accessedAt, teamId, database and the sequence, and the documents
        // handed to the workers are reloaded there by id. Same group
        // Action::$filters marks as Project.
        $project = $this->dbForPlatform->skipFilters(
            fn () => $this->dbForPlatform->getDocument('projects', $projectId),
            ['subQueryKeys', 'subQueryWebhooks', 'subQueryPlatforms', 'subQueryBlocks', 'subQueryDevKeys']
        );

        return $this->projects[$projectId] = $project;
    }

    private function deleteOrphan(string $scheduleId): void
    {
        \go(function () use ($scheduleId) {
            try {
                $this->dbForPlatform->deleteDocument('schedules', $scheduleId);
            } catch (\Throwable $th) {
                Console::error("Failed to delete orphaned schedule {$scheduleId}: {$th->getMessage()}");
            }
        });
    }
}
