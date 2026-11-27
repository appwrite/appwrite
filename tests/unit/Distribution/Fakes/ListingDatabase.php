<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Fakes;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\PermissionType;
use Utopia\Database\Query;
use Utopia\Query\Method;

final class ListingDatabase extends Database
{
    private const string EPOCH = '2026-01-01T00:00:00.000+00:00';

    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $collections = [];

    /**
     * @var list<array{collection: string, id: string, attributes: array<string, mixed>}>
     */
    private array $writes = [];

    /**
     * @var list<array{collection: string, queries: list<Query>}>
     */
    private array $finds = [];

    /**
     * @var list<array{collection: string, id: string, attributes: array<string, mixed>}> applied one per optimistic write, between its read and its write
     */
    private array $interference = [];

    private ?\DateTime $timestamp = null;

    private int $sequence = 0;

    private int $clock = 0;

    public function __construct()
    {
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function seed(string $collection, array $attributes): Document
    {
        $attributes['$sequence'] ??= (string) ++$this->sequence;
        $attributes['$updatedAt'] = $this->tick();
        $this->collections[$collection][(string) $attributes['$id']] = $attributes;

        return new Document($attributes);
    }

    /**
     * A write another process makes after the next optimistic write read its document.
     *
     * @param array<string, mixed> $attributes
     */
    public function interfere(string $collection, string $id, array $attributes): void
    {
        $this->interference[] = ['collection' => $collection, 'id' => $id, 'attributes' => $attributes];
    }

    /**
     * @return list<array{collection: string, id: string, attributes: array<string, mixed>}>
     */
    public function writes(?string $collection = null): array
    {
        return \array_values(\array_filter($this->writes, static fn (array $write): bool => $collection === null || $write['collection'] === $collection));
    }

    /**
     * @return list<list<Query>>
     */
    public function finds(string $collection): array
    {
        return \array_values(\array_map(
            static fn (array $find): array => $find['queries'],
            \array_filter($this->finds, static fn (array $find): bool => $find['collection'] === $collection),
        ));
    }

    #[\Override]
    public function withRequestTimestamp(?\DateTime $requestTimestamp, callable $callback): mixed
    {
        $interference = \array_shift($this->interference);

        if ($interference !== null) {
            $this->store($interference['collection'], $interference['id'], $interference['attributes']);
        }

        $previous = $this->timestamp;
        $this->timestamp = $requestTimestamp;

        try {
            return $callback();
        } finally {
            $this->timestamp = $previous;
        }
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

        if ($collection === 'distributionSubmissions' && \array_any(
            $this->collections[$collection] ?? [],
            static fn (array $row): bool => ($row['appInternalId'] ?? null) === ($attributes['appInternalId'] ?? null) && ($row['key'] ?? null) === ($attributes['key'] ?? null),
        )) {
            throw new Duplicate('Document already exists');
        }

        $this->writes[] = ['collection' => $collection, 'id' => (string) $attributes['$id'], 'attributes' => $attributes];

        return $this->seed($collection, $attributes);
    }

    #[\Override]
    public function updateDocument(string $collection, string $id, Document $document): Document
    {
        $stored = $this->collections[$collection][$id]['$updatedAt'] ?? null;

        if ($this->timestamp !== null && \is_string($stored) && new \DateTime($stored) > $this->timestamp) {
            throw new Conflict('Document was updated after the request timestamp');
        }

        return $this->store($collection, $id, $document->getArrayCopy());
    }

    #[\Override]
    public function find(string $collection, array $queries = [], PermissionType $forPermission = PermissionType::Read): array
    {
        $this->finds[] = ['collection' => $collection, 'queries' => \array_values($queries)];
        $rows = \array_values($this->collections[$collection] ?? []);
        $limit = null;
        $select = null;
        $cursor = null;
        $order = null;
        $descending = false;

        foreach ($queries as $query) {
            $attribute = $query->getAttribute();
            $values = \array_map(static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '', $query->getValues());

            match ($query->getMethod()) {
                Method::Equal => $rows = \array_filter($rows, static fn (array $row): bool => \in_array((string) ($row[$attribute] ?? ''), $values, true)),
                Method::NotEqual => $rows = \array_filter($rows, static fn (array $row): bool => !\in_array((string) ($row[$attribute] ?? ''), $values, true)),
                Method::OrderAsc, Method::OrderDesc => [$order, $descending] = [$attribute, $query->getMethod() === Method::OrderDesc],
                Method::CursorAfter => $cursor = $query->getValue(),
                Method::Limit => $limit = (int) $query->getValue(),
                Method::Select => $select = $values,
                default => throw new \LogicException("Query {$query->getMethod()->value} is not served by this fake"),
            };
        }

        if ($order !== null) {
            \usort($rows, static fn (array $a, array $b): int => $descending ? (int) $b[$order] <=> (int) $a[$order] : (int) $a[$order] <=> (int) $b[$order]);
        }

        if ($cursor instanceof Document) {
            $position = (int) $cursor->getSequence();
            $rows = \array_filter($rows, static fn (array $row): bool => $descending ? (int) $row['$sequence'] < $position : (int) $row['$sequence'] > $position);
        }

        $rows = \array_slice(\array_values($rows), 0, $limit);

        if ($select !== null) {
            $rows = \array_map(static fn (array $row): array => \array_intersect_key($row, \array_flip($select)), $rows);
        }

        return \array_map(static fn (array $row): Document => new Document($row), $rows);
    }

    #[\Override]
    public function findOne(string $collection, array $queries = []): Document
    {
        return $this->find($collection, [...$queries, Query::limit(1)])[0] ?? new Document();
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function store(string $collection, string $id, array $changes): Document
    {
        $this->writes[] = ['collection' => $collection, 'id' => $id, 'attributes' => $changes];
        $this->collections[$collection][$id] = [...$this->collections[$collection][$id] ?? [], ...$changes, '$updatedAt' => $this->tick()];

        return new Document($this->collections[$collection][$id]);
    }

    private function tick(): string
    {
        return (new \DateTimeImmutable(self::EPOCH))->modify('+' . ++$this->clock . ' seconds')->format('Y-m-d\TH:i:s.vP');
    }
}
