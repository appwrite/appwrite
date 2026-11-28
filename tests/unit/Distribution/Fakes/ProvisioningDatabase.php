<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Fakes;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\PermissionType;
use Utopia\Database\Query;
use Utopia\Query\Method;

/**
 * Signing identities in memory, matching `equal` and `notEqual` queries, with
 * a write that can be made to fail, transactions that roll back, and a commit
 * that can be made to report a failure after it applied.
 */
final class ProvisioningDatabase extends Database
{
    /**
     * @var array<string, array<string, Document>>
     */
    public array $documents = [];

    /**
     * Fails the updateDocument call with this number, counted from the first call.
     */
    public ?int $failUpdateAfter = null;

    public bool $failCommit = false;

    public int $updates = 0;

    /**
     * Runs before every update, to record it alongside other calls.
     *
     * @var (\Closure(string): void)|null
     */
    public ?\Closure $onUpdate = null;

    private int $sequence = 0;

    public function __construct()
    {
    }

    #[\Override]
    public function getDocument(string $collection, string $id, array $queries = [], bool $forUpdate = false): Document
    {
        return $this->documents[$collection][$id] ?? new Document();
    }

    #[\Override]
    public function createDocument(string $collection, Document $document): Document
    {
        if (isset($this->documents[$collection][$document->getId()])) {
            throw new Duplicate('Document already exists');
        }

        return $this->documents[$collection][$document->getId()] = new Document([
            ...$document->getArrayCopy(),
            '$sequence' => (string) ++$this->sequence,
            '$collection' => $collection,
        ]);
    }

    #[\Override]
    public function updateDocument(string $collection, string $id, Document $document): Document
    {
        $this->updates++;
        ($this->onUpdate)?->__invoke($id);

        if ($this->updates === $this->failUpdateAfter) {
            throw new DatabaseException('Write failed');
        }

        return $this->documents[$collection][$id] = new Document([
            ...$this->getDocument($collection, $id)->getArrayCopy(),
            ...$document->getArrayCopy(),
        ]);
    }

    #[\Override]
    public function withTransaction(callable $callback): mixed
    {
        $documents = $this->documents;

        try {
            $result = $callback();
        } catch (\Throwable $error) {
            $this->documents = $documents;

            throw $error;
        }

        if ($this->failCommit) {
            throw new DatabaseException('Commit failed');
        }

        return $result;
    }

    #[\Override]
    public function find(string $collection, array $queries = [], PermissionType $forPermission = PermissionType::Read): array
    {
        return \array_values(\array_filter(
            $this->documents[$collection] ?? [],
            static fn (Document $document): bool => \array_all($queries, static fn (Query $query): bool => match ($query->getMethod()) {
                Method::Equal => \in_array($document->getAttribute($query->getAttribute()), $query->getValues(), true),
                Method::NotEqual => !\in_array($document->getAttribute($query->getAttribute()), $query->getValues(), true),
                default => true,
            }),
        ));
    }
}
