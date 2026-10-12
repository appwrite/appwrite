<?php

declare(strict_types=1);

namespace Tests\Unit\Schedule\Source;

use Utopia\Database\Database;
use Utopia\Database\Document;

final class ScheduleDatabase extends Database
{
    /** @var array<string, array<string, Document>> */
    public array $documents;

    public ?\RuntimeException $readError = null;

    public ?\RuntimeException $deleteError = null;

    public function __construct()
    {
        $this->documents = [
            'projects' => [],
            'functions' => ['function' => new Document(['$id' => 'function'])],
            'schedules' => ['schedule' => new Document([
                '$id' => 'schedule',
                '$sequence' => '1',
                'projectId' => 'project',
                'resourceId' => 'function',
                'resourceType' => SCHEDULE_RESOURCE_TYPE_FUNCTION,
                'schedule' => '* * * * *',
                'active' => true,
                'resourceUpdatedAt' => '2026-09-09 00:00:00.000',
            ])],
        ];
    }

    public function skipFilters(callable $callback, ?array $filters = null): mixed
    {
        return $callback();
    }

    public function getDocument(string $collection, string $id, array $queries = [], bool $forUpdate = false): Document
    {
        if ($collection === 'projects' && $this->readError !== null) {
            throw $this->readError;
        }

        return $this->documents[$collection][$id] ?? new Document();
    }

    public function deleteDocument(string $collection, string $id): bool
    {
        if ($this->deleteError !== null) {
            throw $this->deleteError;
        }
        unset($this->documents[$collection][$id]);

        return true;
    }

    public function find(string $collection, array $queries = [], string $forPermission = Database::PERMISSION_READ): array
    {
        return array_values($this->documents[$collection] ?? []);
    }
}
