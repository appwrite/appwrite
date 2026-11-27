<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Fakes;

use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Exception\NotFound;
use Utopia\Database\PermissionType;
use Utopia\Database\Query;
use Utopia\Query\Method;

/**
 * A Database holding documents in memory with the parts of the real one leases
 * rely on: every write moves `$updatedAt` forward, `updateDocument()` refuses
 * with a conflict a row written after the request timestamp, and `find()`
 * matches, orders and limits. `$sequence` is decimal digits, or a uuid7 like
 * MongoDB's when `$uuid` is set.
 */
final class LeaseDatabase extends Database
{
    /** @var array<string, array<string, Document>> */
    public array $documents = [];

    /** @var list<string> `{collection}:{id}` of every purge */
    public array $purged = [];

    public int $writes = 0;

    public bool $uuid = false;

    /**
     * Runs before every update while set, to interleave a concurrent writer.
     *
     * @var (\Closure(string, string): void)|null
     */
    public ?\Closure $beforeUpdate = null;

    private int $sequence = 0;

    private ?string $clock = null;

    private ?\DateTime $requestTimestamp = null;

    public function __construct()
    {
    }

    /**
     * Stores a row as is, without hooks, keeping a given `$updatedAt`.
     *
     * @param array<string, mixed> $attributes
     */
    public function seed(string $collection, array $attributes): Document
    {
        $this->sequence++;
        $id = (string) ($attributes['$id'] ?? 'document' . $this->sequence);

        return $this->documents[$collection][$id] = new Document([
            '$sequence' => $this->nextSequence(),
            '$updatedAt' => $this->tick(),
            ...$attributes,
            '$id' => $id,
            '$collection' => $collection,
        ]);
    }

    /**
     * Writes a row as a concurrent writer would, moving its `$updatedAt`.
     */
    public function bump(string $collection, string $id): void
    {
        $this->documents[$collection][$id] = new Document([
            ...$this->documents[$collection][$id]->getArrayCopy(),
            '$updatedAt' => $this->tick(),
        ]);
    }

    #[\Override]
    public function getDocument(string $collection, string $id, array $queries = [], bool $forUpdate = false): Document
    {
        $document = $this->documents[$collection][$id] ?? null;

        return $document === null ? new Document() : new Document($document->getArrayCopy());
    }

    #[\Override]
    public function createDocument(string $collection, Document $document): Document
    {
        $id = $document->getId();
        if ($id !== '' && isset($this->documents[$collection][$id])) {
            throw new Duplicate('Document already exists');
        }

        $this->writes++;
        $this->sequence++;
        $id = $id !== '' ? $id : 'document' . $this->sequence;
        $now = $this->tick();

        $this->documents[$collection][$id] = new Document([
            ...$document->getArrayCopy(),
            '$id' => $id,
            '$sequence' => $this->nextSequence(),
            '$collection' => $collection,
            '$createdAt' => $now,
            '$updatedAt' => $now,
        ]);

        return $this->getDocument($collection, $id);
    }

    #[\Override]
    public function updateDocument(string $collection, string $id, Document $document): Document
    {
        $this->beforeUpdate?->__invoke($collection, $id);

        $old = $this->documents[$collection][$id] ?? null;
        if ($old === null) {
            throw new NotFound('Document not found');
        }

        if ($this->requestTimestamp !== null && new \DateTime((string) $old->getUpdatedAt()) > $this->requestTimestamp) {
            throw new Conflict('Document was updated after the request timestamp');
        }

        $this->writes++;
        $this->documents[$collection][$id] = new Document([
            ...$old->getArrayCopy(),
            ...$document->getArrayCopy(),
            '$id' => $id,
            '$updatedAt' => $this->tick(),
        ]);

        return $this->getDocument($collection, $id);
    }

    #[\Override]
    public function withRequestTimestamp(?\DateTime $requestTimestamp, callable $callback): mixed
    {
        $previous = $this->requestTimestamp;
        $this->requestTimestamp = $requestTimestamp;

        try {
            return $callback();
        } finally {
            $this->requestTimestamp = $previous;
        }
    }

    #[\Override]
    public function purgeCachedDocument(string $collection, string $id): void
    {
        $this->purged[] = "{$collection}:{$id}";
    }

    #[\Override]
    public function find(string $collection, array $queries = [], PermissionType $forPermission = PermissionType::Read): array
    {
        $documents = \array_values(\array_filter(
            $this->documents[$collection] ?? [],
            fn (Document $document): bool => \array_all($queries, fn (Query $query): bool => $this->matches($document, $query)),
        ));

        foreach ($queries as $query) {
            $direction = match ($query->getMethod()) {
                Method::OrderAsc => 1,
                Method::OrderDesc => -1,
                default => 0,
            };
            if ($direction !== 0) {
                \usort($documents, fn (Document $a, Document $b): int => $direction * ($a->getAttribute($query->getAttribute()) <=> $b->getAttribute($query->getAttribute())));
            }
        }

        foreach ($queries as $query) {
            if ($query->getMethod() === Method::Limit) {
                $documents = \array_slice($documents, 0, (int) $query->getValue());
            }
        }

        return \array_map(fn (Document $document): Document => new Document($document->getArrayCopy()), $documents);
    }

    #[\Override]
    public function findOne(string $collection, array $queries = []): Document
    {
        return $this->find($collection, [...$queries, Query::limit(1)])[0] ?? new Document();
    }

    #[\Override]
    public function count(string $collection, array $queries = [], ?int $max = null): int
    {
        return \count($this->find($collection, $queries));
    }

    private function matches(Document $document, Query $query): bool
    {
        $value = $document->getAttribute($query->getAttribute());

        return match ($query->getMethod()) {
            Method::Equal => \in_array($value, $query->getValues(), true),
            Method::NotEqual => !\in_array($value, $query->getValues(), true),
            Method::GreaterThan => $value !== null && $value > $query->getValue(),
            Method::GreaterThanEqual => $value !== null && $value >= $query->getValue(),
            Method::OrderAsc, Method::OrderDesc, Method::Limit => true,
            default => throw new \LogicException("LeaseDatabase does not support {$query->getMethod()->value} queries."),
        };
    }

    private function tick(): string
    {
        return $this->clock = DateTime::nowAfter($this->clock);
    }

    private function nextSequence(): string
    {
        return $this->uuid
            ? \sprintf('0192f0c5-7a1b-7c3d-8e4f-%012x', $this->sequence)
            : (string) $this->sequence;
    }
}
