<?php

namespace Appwrite\Schedule\Source;

use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Schedule\Changes;
use Utopia\Schedule\Source;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Source\Row;
use Utopia\Schedule\Trigger;
use Utopia\Span\Span;
use Utopia\System\System;

abstract class Database implements Source, Changes
{
    /** @var array<string, Document> */
    private array $projects = [];

    /**
     * @param callable(Document): \Utopia\Database\Database $getProjectDB
     * @param callable(Document, string, string): bool $isResourceBlocked
     */
    public function __construct(
        protected readonly \Utopia\Database\Database $dbForPlatform,
        protected readonly mixed $getProjectDB,
        protected readonly mixed $isResourceBlocked,
    ) {
    }

    abstract protected function type(): string;

    abstract protected function collection(): string;

    /**
     * @param array<string, mixed> $schedule
     */
    abstract protected function trigger(array $schedule): Trigger;

    #[\Override]
    public function snapshot(): iterable
    {
        yield from $this->rows(null);
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

        if (($this->isResourceBlocked)($project, $this->collection(), $schedule['resourceId'])) {
            throw new \InvalidArgumentException("Resource blocked: {$schedule['resourceId']}");
        }

        $schedule['project'] = $project;
        $schedule['resource'] = $this->resource(($this->getProjectDB)($project), $schedule);

        if ($schedule['resource']->isEmpty()) {
            $this->deleteOrphan($document->getId());

            throw new \InvalidArgumentException("Resource not found: {$schedule['resourceId']}");
        }

        return new Entry($this->trigger($schedule), $schedule);
    }

    /**
     * @param array<string, mixed> $schedule
     */
    protected function resource(\Utopia\Database\Database $projectDB, array $schedule): Document
    {
        return $projectDB->getDocument($this->collection(), $schedule['resourceId']);
    }

    /**
     * @return iterable<Row>
     */
    private function rows(?\DateTimeImmutable $since): iterable
    {
        $region = System::getEnv('_APP_REGION', 'default');

        $limit = 10_000;
        $sum = $limit;
        $latest = null;

        while ($sum === $limit) {
            $queries = [
                Query::limit($limit),
                Query::equal('region', [$region]),
                Query::equal('resourceType', [$this->type()]),
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
                $updatedAt = (string) $schedule->getAttribute('resourceUpdatedAt', '');

                yield new Row(
                    id: (string) $schedule->getSequence(),
                    version: $updatedAt,
                    data: $schedule,
                    active: (bool) $schedule->getAttribute('active', false),
                    activeFrom: $this->moment($updatedAt),
                );
            }

            $latest = \end($schedules) ?: null;
        }
    }

    private function moment(string $stamp): ?\DateTimeImmutable
    {
        try {
            return $stamp === '' ? null : new \DateTimeImmutable($stamp);
        } catch (\Throwable) {
            return null;
        }
    }

    private function project(string $projectId): Document
    {
        if (isset($this->projects[$projectId])) {
            return $this->projects[$projectId];
        }

        $project = $this->dbForPlatform->skipFilters(
            fn () => $this->dbForPlatform->getDocument('projects', $projectId),
            APP_PROJECTS_SUBQUERIES
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
