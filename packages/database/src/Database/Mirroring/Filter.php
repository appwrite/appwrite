<?php

namespace Utopia\Database\Mirroring;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;

abstract class Filter
{
    /**
     * Called before any action is executed, when the filter is constructed.
     *
     * @param Database $source
     * @param ?Database $destination
     * @return void
     */
    public function init(
        Database $source,
        ?Database $destination,
    ): void {
    }

    /**
     * Called after all actions are executed, when the filter is destructed.
     *
     * @param Database $source
     * @param ?Database $destination
     * @return void
     */
    public function shutdown(
        Database $source,
        ?Database $destination,
    ): void {
    }

    /**
     * Called before collection is created in the destination database
     *
     * @param Database $source
     * @param Database $destination
     * @param string $collectionId
     * @param ?Document $collection
     * @return ?Document
     */
    public function beforeCreateCollection(
        Database $source,
        Database $destination,
        string $collectionId,
        ?Document $collection = null,
    ): ?Document {
        return $collection;
    }

    /**
     * Called before collection is updated in the destination database
     *
     * @param Database $source
     * @param Database $destination
     * @param string $collectionId
     * @param ?Document $collection
     * @return ?Document
     */
    public function beforeUpdateCollection(
        Database $source,
        Database $destination,
        string $collectionId,
        ?Document $collection = null,
    ): ?Document {
        return $collection;
    }

    /**
     * Called after collection is deleted in the destination database
     *
     * @param Database $source
     * @param Database $destination
     * @param string $collectionId
     * @return void
     */
    public function beforeDeleteCollection(
        Database $source,
        Database $destination,
        string $collectionId,
    ): void {
    }

    /**
     * @param Database $source
     * @param Database $destination
     * @param string $collectionId
     * @param string $attributeId
     * @param ?Document $attribute
     * @return ?Document
     */
    public function beforeCreateAttribute(
        Database $source,
        Database $destination,
        string $collectionId,
        string $attributeId,
        ?Document $attribute = null,
    ): ?Document {
        return $attribute;
    }

    /**
     * @param Database $source
     * @param Database $destination
     * @param string $collectionId
     * @param string $attributeId
     * @param ?Document $attribute
     * @return ?Document
     */
    public function beforeUpdateAttribute(
        Database $source,
        Database $destination,
        string $collectionId,
        string $attributeId,
        ?Document $attribute = null,
    ): ?Document {
        return $attribute;
    }

    /**
     * @param Database $source
     * @param Database $destination
     * @param string $collectionId
     * @param string $attributeId
     * @return void
     */
    public function beforeDeleteAttribute(
        Database $source,
        Database $destination,
        string $collectionId,
        string $attributeId,
    ): void {
    }

    // Indexes

    /**
     * @param Database $source
     * @param Database $destination
     * @param string $collectionId
     * @param string $indexId
     * @param ?Document $index
     * @return ?Document
     */
    public function beforeCreateIndex(
        Database $source,
        Database $destination,
        string $collectionId,
        string $indexId,
        ?Document $index = null,
    ): ?Document {
        return $index;
    }

    /**
     * @param Database $source
     * @param Database $destination
     * @param string $collectionId
     * @param string $indexId
     * @param ?Document $index
     * @return ?Document
     */
    public function beforeUpdateIndex(
        Database $source,
        Database $destination,
        string $collectionId,
        string $indexId,
        ?Document $index = null,
    ): ?Document {
        return $index;
    }

    /**
     * @param Database $source
     * @param Database $destination
     * @param string $collectionId
     * @param string $indexId
     * @return void
     */
    public function beforeDeleteIndex(
        Database $source,
        Database $destination,
        string $collectionId,
        string $indexId,
    ): void {
    }

    /**
     * Called before document is created in the destination database
     *
     * @param Database $source
     * @param Database $destination
     * @param string $collectionId
     * @param Document $document
     * @return Document
     */
    public function beforeCreateDocument(
        Database $source,
        Database $destination,
        string $collectionId,
        Document $document,
    ): Document {
        return $document;
    }

    /**
     * Called after document is created in the destination database
     *
     * @param Database $source
     * @param Database $destination
     * @param string $collectionId
     * @param Document $document
     * @return Document
     */
    public function afterCreateDocument(
        Database $source,
        Database $destination,
        string $collectionId,
        Document $document,
    ): Document {
        return $document;
    }

    /**
     * Called before document is updated in the destination database
     *
     * @param Database $source
     * @param Database $destination
     * @param string $collectionId
     * @param Document $document
     * @return Document
     */
    public function beforeUpdateDocument(
        Database $source,
        Database $destination,
        string $collectionId,
        Document $document,
    ): Document {
        return $document;
    }

    /**
     * Called after document is updated in the destination database
     *
     * @param Database $source
     * @param Database $destination
     * @param string $collectionId
     * @param Document $document
     * @return Document
     */
    public function afterUpdateDocument(
        Database $source,
        Database $destination,
        string $collectionId,
        Document $document,
    ): Document {
        return $document;
    }

    /**
     * @param Database $source
     * @param Database $destination
     * @param string $collectionId
     * @param Document $updates
     * @param array<Query> $queries
     * @return Document
     */
    public function beforeUpdateDocuments(
        Database $source,
        Database $destination,
        string $collectionId,
        Document $updates,
        array $queries
    ): Document {
        return $updates;
    }

    /**
     * @param Database $source
     * @param Database $destination
     * @param string $collectionId
     * @param Document $updates
     * @param array<Query> $queries
     * @return void
     */
    public function afterUpdateDocuments(
        Database $source,
        Database $destination,
        string $collectionId,
        Document $updates,
        array $queries
    ): void {
    }

    /**
     * Called before document is deleted in the destination database
     *
     * @param Database $source
     * @param Database $destination
     * @param string $collectionId
     * @param string $documentId
     * @return void
     */
    public function beforeDeleteDocument(
        Database $source,
        Database $destination,
        string $collectionId,
        string $documentId,
    ): void {
    }

    /**
     * Called after document is deleted in the destination database
     *
     * @param Database $source
     * @param Database $destination
     * @param string $collectionId
     * @param string $documentId
     * @return void
     */
    public function afterDeleteDocument(
        Database $source,
        Database $destination,
        string $collectionId,
        string $documentId,
    ): void {
    }

    /**
     * @param Database $source
     * @param Database $destination
     * @param string $collectionId
     * @param array<Query> $queries
     * @return void
     */
    public function beforeDeleteDocuments(
        Database $source,
        Database $destination,
        string $collectionId,
        array $queries
    ): void {
    }

    /**
     * @param Database $source
     * @param Database $destination
     * @param string $collectionId
     * @param array<Query> $queries
     * @return void
     */
    public function afterDeleteDocuments(
        Database $source,
        Database $destination,
        string $collectionId,
        array $queries
    ): void {
    }

    /**
     * Called before document is upserted in the destination database
     *
     * @param Database $source
     * @param Database $destination
     * @param string $collectionId
     * @param Document $document
     * @return Document
     */
    public function beforeCreateOrUpdateDocument(
        Database $source,
        Database $destination,
        string $collectionId,
        Document $document,
    ): Document {
        return $document;
    }

    /**
     * Called after document is upserted in the destination database
     *
     * @param Database $source
     * @param Database $destination
     * @param string $collectionId
     * @param Document $document
     * @return Document
     */
    public function afterCreateOrUpdateDocument(
        Database $source,
        Database $destination,
        string $collectionId,
        Document $document,
    ): Document {
        return $document;
    }
}
