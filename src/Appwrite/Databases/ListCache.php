<?php

namespace Appwrite\Databases;

use Appwrite\Usage\Operations;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;

/**
 * The documents and the operations they were metered for are cached together, so a hit meters what its miss metered.
 */
final readonly class ListCache
{
    public const string DOCUMENTS = 'documents';
    public const string TOTAL = 'total';
    public const string OPERATIONS = 'operations';

    private string $variation;

    /**
     * @param array<mixed> $roles
     * @param array<Query|string> $queries
     */
    public function __construct(
        private Cache $cache,
        private string $key,
        Document $collection,
        array $roles,
        array $queries,
    ) {
        $schema = \md5(
            \json_encode($collection->getAttribute('attributes', []))
            . \json_encode($collection->getAttribute('indexes', []))
        );

        $serialized = \array_map(
            static fn (mixed $query): mixed => $query instanceof Query ? $query->toArray() : $query,
            $queries,
        );

        $this->variation = \sprintf(
            '%s:%s:%s',
            $schema,
            \md5(\json_encode($roles)),
            \md5(\json_encode($serialized)),
        );
    }

    public static function key(Database $dbForProject, Document $database, string $collectionId): string
    {
        return \sprintf(
            '%s-cache:%s:%s:%s:database:%s:collection:%s',
            $dbForProject->getCacheName(),
            $dbForProject->getAdapter()->hostname(),
            $dbForProject->getNamespace(),
            $dbForProject->getTenant(),
            $database->getSequence(),
            $collectionId,
        );
    }

    /**
     * @return array<Document>|null
     */
    public function documents(int $ttl, Operations $operations): ?array
    {
        $cached = $this->load(self::DOCUMENTS, $ttl);
        if (!\is_array($cached)) {
            return null;
        }

        $documents = \array_map(static fn (array $document): Document => new Document($document), $cached);
        $this->record($operations, $documents, $this->load(self::OPERATIONS, $ttl));

        return $documents;
    }

    /**
     * @param array<Document> $documents
     */
    public function saveDocuments(array $documents, Operations $operations): void
    {
        if ($documents === []) {
            return;
        }

        $copies = \array_map(static fn (Document $document): array => $document->getArrayCopy(), $documents);

        try {
            $this->cache->saveMany($this->key, [
                $this->field(self::DOCUMENTS) => $copies,
                $this->field(self::OPERATIONS) => $operations->counts($documents),
            ]);
        } catch (\Throwable) {
        }
    }

    public function total(int $ttl): ?int
    {
        $total = $this->load(self::TOTAL, $ttl);

        return $total === null || $total === false ? null : (int) $total;
    }

    public function saveTotal(int $total): void
    {
        try {
            $this->cache->save($this->key, (string) $total, $this->field(self::TOTAL));
        } catch (\Throwable) {
        }
    }

    private function field(string $type): string
    {
        return $this->variation . ':' . $type;
    }

    private function load(string $type, int $ttl): mixed
    {
        try {
            return $this->cache->load($this->key, $ttl, $this->field($type));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<Document> $documents
     */
    private function record(Operations $operations, array $documents, mixed $counts): void
    {
        $documents = \array_values($documents);
        if (!\is_array($counts) || !\array_is_list($counts) || \count($counts) !== \count($documents)) {
            return;
        }

        foreach ($counts as $count) {
            if (!\is_int($count) || $count < 1) {
                return;
            }
        }

        foreach ($documents as $index => $document) {
            $operations->record($document, $counts[$index]);
        }
    }
}
