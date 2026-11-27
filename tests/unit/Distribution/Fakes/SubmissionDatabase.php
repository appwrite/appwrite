<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Fakes;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\PermissionType;
use Utopia\Database\Query;
use Utopia\Query\Method;

final class SubmissionDatabase extends Database
{
    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $collections = [];

    /**
     * @var list<array{collection: string, id: string, attributes: array<string, mixed>}>
     */
    private array $writes = [];

    private int $sequence = 0;

    /**
     * Rows another writer creates between a read and the next create in the same collection.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $racing = [];

    public function __construct()
    {
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function seed(string $collection, array $attributes): Document
    {
        $attributes['$sequence'] ??= (string) ++$this->sequence;
        $this->collections[$collection][(string) $attributes['$id']] = $attributes;

        return new Document($attributes);
    }

    /**
     * @return list<array{collection: string, id: string, attributes: array<string, mixed>}>
     */
    public function writes(string $collection): array
    {
        return \array_values(\array_filter($this->writes, static fn (array $write): bool => $write['collection'] === $collection));
    }

    #[\Override]
    public function getDocument(string $collection, string $id, array $queries = [], bool $forUpdate = false): Document
    {
        return new Document($this->collections[$collection][$id] ?? []);
    }

    #[\Override]
    public function createDocument(string $collection, Document $document): Document
    {
        $attributes = $document->getArrayCopy();

        if (isset($this->racing[$collection])) {
            $this->seed($collection, $this->racing[$collection]);
            unset($this->racing[$collection]);
        }

        $unique = match ($collection) {
            'distributionSubmissions' => 'key',
            'distributionReleases' => 'version',
            default => null,
        };

        if ($unique !== null && $this->find($collection, [
            Query::equal('appInternalId', [$attributes['appInternalId'] ?? '']),
            Query::equal($unique, [$attributes[$unique] ?? '']),
        ]) !== []) {
            throw new Duplicate('Document already exists');
        }

        $this->writes[] = ['collection' => $collection, 'id' => (string) $attributes['$id'], 'attributes' => $attributes];

        return $this->seed($collection, $attributes);
    }

    #[\Override]
    public function updateDocument(string $collection, string $id, Document $document): Document
    {
        $changes = $document->getArrayCopy();
        $this->writes[] = ['collection' => $collection, 'id' => $id, 'attributes' => $changes];
        $this->collections[$collection][$id] = [...$this->collections[$collection][$id] ?? [], ...$changes];

        return new Document($this->collections[$collection][$id]);
    }

    #[\Override]
    public function find(string $collection, array $queries = [], PermissionType $forPermission = PermissionType::Read): array
    {
        $rows = \array_values($this->collections[$collection] ?? []);
        $limit = null;

        foreach ($queries as $query) {
            $attribute = $query->getAttribute();
            $values = \array_map(static fn (mixed $value): string => (string) $value, $query->getValues());

            match ($query->getMethod()) {
                Method::Equal => $rows = \array_filter($rows, static fn (array $row): bool => \in_array((string) ($row[$attribute] ?? ''), $values, true)),
                Method::NotEqual => $rows = \array_filter($rows, static fn (array $row): bool => !\in_array((string) ($row[$attribute] ?? ''), $values, true)),
                Method::OrderAsc => \usort($rows, static fn (array $a, array $b): int => (int) $a[$attribute] <=> (int) $b[$attribute]),
                Method::OrderDesc => \usort($rows, static fn (array $a, array $b): int => (int) $b[$attribute] <=> (int) $a[$attribute]),
                Method::Limit => $limit = (int) $query->getValue(),
                Method::Select => null,
                default => throw new \LogicException("Query {$query->getMethod()->value} is not served by this fake"),
            };
        }

        $rows = \array_slice(\array_values($rows), 0, $limit);

        return \array_map(static fn (array $row): Document => new Document($row), $rows);
    }

    #[\Override]
    public function count(string $collection, array $queries = [], ?int $max = null): int
    {
        $count = \count($this->find($collection, $queries));

        return $max === null ? $count : \min($count, $max);
    }

    #[\Override]
    public function findOne(string $collection, array $queries = []): Document
    {
        return $this->find($collection, [...$queries, Query::limit(1)])[0] ?? new Document();
    }
}
