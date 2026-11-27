<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Fakes;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Method;

/**
 * A Database holding documents in memory, matching `equal` and `notEqual` queries.
 */
final class MemoryDatabase extends Database
{
    /** @var array<string, array<string, Document>> */
    public array $documents = [];

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
        $matches = $this->matching($collection, $queries);
        foreach ($matches as $document) {
            $this->updateDocument($collection, $document->getId(), $updates);
        }

        return \count($matches);
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
        return \array_values(\array_filter(
            $this->documents[$collection] ?? [],
            fn (Document $document): bool => \array_all($queries, fn (Query $query): bool => match ($query->getMethod()) {
                Method::Equal => \in_array($document->getAttribute($query->getAttribute()), $query->getValues(), true),
                Method::NotEqual => $document->getAttribute($query->getAttribute()) !== $query->getValue(),
                default => true,
            }),
        ));
    }
}
