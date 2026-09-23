<?php

namespace Appwrite\Usage;

use Closure;
use Utopia\Database\Document;
use Utopia\Query\Schema\ColumnType;
use WeakMap;

/**
 * The database operations a request is metered for: each document it reads or writes, plus each related document
 * nested in one.
 */
final readonly class Operations
{
    /**
     * @var WeakMap<Document, int>
     */
    private WeakMap $documents;

    public function __construct()
    {
        $this->documents = new WeakMap();
    }

    public function record(Document $document, int $operations): void
    {
        $this->documents[$document] = $operations;
    }

    /**
     * @param array<Document> $documents
     */
    public function reads(array $documents): int
    {
        $operations = 0;
        foreach ($documents as $document) {
            $operations += $this->documents[$document] ?? 1;
        }

        return $operations;
    }

    /**
     * @param array<Document|array<string, mixed>> $documents
     * @param callable(string): Document $relatedCollection
     */
    public static function writes(Document $collection, array $documents, callable $relatedCollection): int
    {
        $collections = [];
        $related = static function (string $id) use ($relatedCollection, &$collections): Document {
            return $collections[$id] ??= $relatedCollection($id);
        };

        $operations = 0;
        foreach ($documents as $document) {
            $operations += self::written($collection, $document, $related);
        }

        return $operations;
    }

    /**
     * @param Document|array<string, mixed> $document
     * @param Closure(string): Document $relatedCollection
     */
    private static function written(Document $collection, Document|array $document, Closure $relatedCollection): int
    {
        $operations = 1;

        foreach ($collection->getAttribute('attributes', []) as $attribute) {
            if (!$attribute instanceof Document || $attribute->getAttribute('type') !== ColumnType::Relationship->value) {
                continue;
            }

            $value = $document[$attribute->getAttribute('key')] ?? null;
            foreach (self::values($value) as $relation) {
                if (!self::isDocument($relation)) {
                    continue;
                }

                $operations += self::nestsDocuments($relation)
                    ? self::written($relatedCollection($attribute->getAttribute('relatedCollection', '')), $relation, $relatedCollection)
                    : 1;
            }
        }

        return $operations;
    }

    /**
     * @param Document|array<string, mixed> $document
     */
    private static function nestsDocuments(Document|array $document): bool
    {
        foreach ($document as $value) {
            foreach (self::values($value) as $item) {
                if (self::isDocument($item)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<mixed>
     */
    private static function values(mixed $value): array
    {
        return \is_array($value) && \array_is_list($value) ? $value : [$value];
    }

    private static function isDocument(mixed $value): bool
    {
        return $value instanceof Document || (\is_array($value) && !\array_is_list($value));
    }
}
