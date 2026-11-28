<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Fakes;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Limit;
use Utopia\Database\Exception\NotFound;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\PermissionType;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Method;

/**
 * A Database holding documents in memory, matching `equal`, `notEqual` and `isNull` queries. Like the
 * real query validator, it refuses a `notEqual` with more than one value.
 */
final class MemoryDatabase extends Database
{
    /** @var array<string, array<string, Document>> */
    public array $documents = [];

    /**
     * Runs once, right after the next increment and before it returns, to interleave a concurrent writer.
     *
     * @var (\Closure(): void)|null
     */
    public ?\Closure $onIncrease = null;

    /**
     * Runs once, right before the next bulk update matches, to interleave a concurrent writer.
     *
     * @var (\Closure(): void)|null
     */
    public ?\Closure $onUpdates = null;

    /**
     * Runs right before each document is created, with its collection, to observe the writes around it.
     *
     * @var (\Closure(string): void)|null
     */
    public ?\Closure $onCreate = null;

    private int $sequence = 0;

    private Authorization $skipping;

    public function __construct()
    {
        $this->skipping = new Authorization();
    }

    #[\Override]
    public function getAuthorization(): Authorization
    {
        return $this->skipping;
    }

    #[\Override]
    public function getDocument(string $collection, string $id, array $queries = [], bool $forUpdate = false): Document
    {
        return $this->documents[$collection][$id] ?? new Document();
    }

    #[\Override]
    public function createDocument(string $collection, Document $document): Document
    {
        $this->onCreate?->__invoke($collection);

        $this->sequence++;
        $id = $document->getId() !== '' ? $document->getId() : 'document' . $this->sequence;

        return $this->documents[$collection][$id] = new Document([
            ...$document->getArrayCopy(),
            '$id' => $id,
            '$sequence' => (string) $this->sequence,
            '$collection' => $collection,
        ]);
    }

    #[\Override]
    public function updateDocument(string $collection, string $id, Document $document): Document
    {
        return $this->documents[$collection][$id] = new Document([
            ...$this->getDocument($collection, $id)->getArrayCopy(),
            ...$document->getArrayCopy(),
        ]);
    }

    #[\Override]
    public function updateDocuments(
        string $collection,
        Document $updates,
        array $queries = [],
        int $batchSize = self::BATCH_SIZE,
        ?callable $onNext = null,
    ): int {
        $hook = $this->onUpdates;
        $this->onUpdates = null;
        $hook?->__invoke();

        $matches = $this->matching($collection, $queries);
        foreach ($matches as $document) {
            $this->updateDocument($collection, $document->getId(), $updates);
        }

        return \count($matches);
    }

    #[\Override]
    public function increaseDocumentAttribute(string $collection, string $id, string $attribute, int|float|string $value = 1, int|float|string|null $max = null): Document
    {
        $document = $this->getDocument($collection, $id);
        if ($document->isEmpty()) {
            throw new NotFound('Document not found');
        }

        $increased = ($document->getAttribute($attribute) ?? 0) + $value;
        if ($max !== null && $increased > $max) {
            throw new Limit('Attribute value exceeds maximum limit: ' . $max);
        }

        $document = $this->updateDocument($collection, $id, new Document([
            $attribute => $increased,
        ]));

        $hook = $this->onIncrease;
        $this->onIncrease = null;
        $hook?->__invoke();

        return $document;
    }

    #[\Override]
    public function find(string $collection, array $queries = [], PermissionType $forPermission = PermissionType::Read): array
    {
        return $this->matching($collection, $queries);
    }

    #[\Override]
    public function count(string $collection, array $queries = [], ?int $max = null): int
    {
        return \count($this->matching($collection, $queries));
    }

    #[\Override]
    public function findOne(string $collection, array $queries = []): Document
    {
        return $this->matching($collection, $queries)[0] ?? new Document();
    }

    /**
     * @param array<Query> $queries
     * @return list<Document>
     */
    private function matching(string $collection, array $queries): array
    {
        foreach ($queries as $query) {
            if ($query->getMethod() === Method::NotEqual && \count($query->getValues()) !== 1) {
                throw new QueryException('Invalid query: NotEqual queries require exactly one value.');
            }
        }

        return \array_values(\array_filter(
            $this->documents[$collection] ?? [],
            fn (Document $document): bool => \array_all($queries, fn (Query $query): bool => match ($query->getMethod()) {
                Method::Equal => \in_array($document->getAttribute($query->getAttribute()), $query->getValues(), true),
                Method::NotEqual => !\in_array($document->getAttribute($query->getAttribute()), $query->getValues(), true),
                Method::IsNull => $document->getAttribute($query->getAttribute()) === null,
                default => true,
            }),
        ));
    }
}
