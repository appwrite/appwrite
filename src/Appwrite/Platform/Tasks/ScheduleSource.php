<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Schedule\Changes;
use Utopia\Schedule\Source;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Source\Row;
use Utopia\Span\Span;
use Utopia\System\System;

final class ScheduleSource implements Source, Changes
{
    /** @var array<string, Document> */
    private array $projects = [];

    private int $snapshotted = 0;

    /**
     * @param callable(Document): Database $getProjectDB
     * @param callable(Document, string, string): bool $isResourceBlocked
     * @param \Closure(Database, array<string, mixed>): Document $resource
     * @param \Closure(array<string, mixed>): Entry $entry
     */
    public function __construct(
        private readonly Database $dbForPlatform,
        private readonly mixed $getProjectDB,
        private readonly mixed $isResourceBlocked,
        private readonly string $resourceType,
        private readonly string $collectionId,
        private readonly \Closure $resource,
        private readonly \Closure $entry,
    ) {
    }

    #[\Override]
    public function snapshot(): iterable
    {
        $this->snapshotted = 0;

        foreach ($this->rows(null) as $row) {
            $this->snapshotted++;

            yield $row;
        }
    }

    public function snapshotted(): int
    {
        return $this->snapshotted;
    }

    #[\Override]
    public function since(\DateTimeImmutable $moment): iterable
    {
        yield from $this->rows($moment);
    }

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
            $this->deleteOrphan($document->getId());

            throw new \InvalidArgumentException("Resource not found: {$schedule['resourceId']}");
        }

        return ($this->entry)($schedule);
    }

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
                yield new Row(
                    id: (string) $schedule->getSequence(),
                    version: (string) $schedule->getAttribute('resourceUpdatedAt', ''),
                    data: $schedule,
                    active: (bool) $schedule->getAttribute('active', false),
                    activeFrom: $this->activeFrom($schedule),
                );
            }

            $latest = \end($schedules) ?: null;
        }
    }

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

        return $changed;
    }

    private function project(string $projectId): Document
    {
        if (isset($this->projects[$projectId])) {
            return $this->projects[$projectId];
        }

        $project = $this->dbForPlatform->skipFilters(
            fn () => $this->dbForPlatform->getDocument('projects', $projectId),
            ['subQueryKeys', 'subQueryWebhooks', 'subQueryPlatforms', 'subQueryBlocks', 'subQueryDevKeys']
        );

        return $this->projects[$projectId] = $project;
    }

    private function deleteOrphan(string $scheduleId): void
    {
        \go(function () use ($scheduleId): void {
            Span::init('schedule.orphan.delete');
            Span::add('schedule.id', $scheduleId);
            $error = null;

            try {
                $this->dbForPlatform->deleteDocument('schedules', $scheduleId);
            } catch (\Throwable $th) {
                $error = $th;
            } finally {
                Span::current()?->finish(error: $error);
            }
        });
    }
}
